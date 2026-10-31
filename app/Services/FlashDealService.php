<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Exceptions\MarketplaceException;
use App\Models\FlashDeal;
use App\Models\FlashDealReminder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Sms\SmsMessages;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Lightning deals: short, low prices that rotate every day, with "Remind me" SMS. */
class FlashDealService
{
    public function __construct(private SmsService $sms) {}

    /**
     * Today's deals (running and still to come), soonest first.
     *
     * @return Collection<int, FlashDeal>
     */
    public function today(): Collection
    {
        return FlashDeal::where('ends_at', '>', now())->where('starts_at', '<', today()->addDay())
            ->whereHas('product', fn ($q) => $q->listed())
            ->with(['product.seller', 'product.images', 'product.tiers'])
            ->orderBy('starts_at')->get()
            ->filter(fn (FlashDeal $deal) => $deal->state !== 'ended')->values();
    }

    /** @throws MarketplaceException */
    public function toggleReminder(User $user, FlashDeal $deal): bool
    {
        if ($deal->ends_at->isPast()) {
            throw new MarketplaceException('This deal has ended.');
        }

        $existing = FlashDealReminder::where('flash_deal_id', $deal->id)->where('user_id', $user->id)->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        FlashDealReminder::create(['flash_deal_id' => $deal->id, 'user_id' => $user->id]);

        return true;
    }

    /** SMS everyone who tapped "Remind me" when their deal starts. Runs every minute or five. */
    public function sendReminders(): int
    {
        $reminders = FlashDealReminder::whereNull('notified_at')
            ->whereHas('deal', fn ($q) => $q->where('starts_at', '<=', now()->addMinutes(5))->where('ends_at', '>', now()))
            ->with('deal.product', 'user')->limit(500)->get();

        foreach ($reminders as $reminder) {
            $reminder->forceFill(['notified_at' => now()])->save();
            $this->sms->send($reminder->user->phone, SmsMessages::flashDealStarting($reminder->deal));
        }

        return $reminders->count();
    }

    /** Count units sold at a deal price, so a deal with a quantity limit ends when they are gone. */
    public function recordSales(Order $order): void
    {
        foreach ($order->items as $item) {
            $deal = FlashDeal::where('product_id', $item->product_id)->where('starts_at', '<=', $order->created_at)
                ->where('ends_at', '>', $order->created_at)->first();

            if ($deal && Money::cmp($item->unit_price, $deal->deal_price) === 0) {
                $deal->increment('sold_count', $item->quantity);
            }
        }
    }

    /**
     * Fill a day with automatic deals from products sellers marked as "deal", taking turns
     * (the products used longest ago go first). Admin-made deals that day are kept.
     */
    public function rotate(Carbon $day): int
    {
        $slots = RewardSettings::int('lightning_slots_per_day');
        $hours = max(1, RewardSettings::int('lightning_slot_hours'));
        $percent = min(90, max(1, RewardSettings::int('lightning_discount_percent')));
        $start = $day->copy()->startOfDay()->setTime(8, 0);

        $alreadyToday = FlashDeal::whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])->pluck('product_id');

        $products = Product::where('status', ProductStatus::Active)->where('is_deal', true)->where('stock', '>', 0)
            ->whereNotIn('id', $alreadyToday)
            ->withMax('flashDeals', 'starts_at')
            ->orderByRaw('flash_deals_max_starts_at is not null, flash_deals_max_starts_at')
            ->limit($slots)->get();

        foreach ($products->values() as $i => $product) {
            $from = $start->copy()->addHours($i * $hours);

            FlashDeal::create([
                'product_id' => $product->id,
                'deal_price' => Money::sub($product->price, Money::percentOf($product->price, $percent)),
                'starts_at' => $from,
                'ends_at' => $from->copy()->addHours($hours),
                'max_per_order' => 5,
            ]);
        }

        return $products->count();
    }
}
