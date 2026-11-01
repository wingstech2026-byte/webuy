<?php

namespace App\Services;

use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Checkout;
use App\Models\GroupBuy;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Group buying. A leader starts a group and shares the link; each member checks out and pays the group
 * price for their own quantity, with their own delivery address or the collection point. Stock is
 * reserved at checkout (no overselling). The group succeeds the moment enough members have PAID
 * (counted under a row lock, so simultaneous payments cannot double count). Until then sellers cannot
 * accept the orders. If time runs out, every paid order is cancelled, its stock released and its money
 * refunded; unpaid places are cancelled. All of this is safe to run twice.
 */
class GroupBuyService
{
    public function __construct(
        private CheckoutService $checkouts,
        private OrderService $orders,
        private SmsService $sms,
    ) {}

    /**
     * $variantId is the size or colour each member picks when the product has options (members may pick different ones).
     *
     * @throws MarketplaceException
     */
    public function start(User $leader, GroupBuyCampaign $campaign, int $quantity, array $details, string $method, Operator $operator, string $phone, ?string $deviceHash, ?int $variantId = null): GroupBuyParticipant
    {
        if (! $campaign->isOpen() || ! $campaign->product->isListed()) {
            throw new MarketplaceException('This group offer has ended.');
        }

        // One transaction: when the leader's own place fails (no stock, wrong area...), no empty group is left behind.
        return DB::transaction(function () use ($leader, $campaign, $quantity, $details, $method, $operator, $phone, $deviceHash, $variantId) {
            $group = GroupBuy::create([
                'group_buy_campaign_id' => $campaign->id,
                'leader_id' => $leader->id,
                'token' => Str::lower(Str::random(10)),
                'status' => GroupBuy::OPEN,
                'expires_at' => now()->addHours(max(1, $campaign->hours)),
            ]);

            return $this->join($leader, $group, $quantity, $details, $method, $operator, $phone, $deviceHash, $variantId);
        });
    }

    /** @throws MarketplaceException */
    public function join(User $user, GroupBuy $group, int $quantity, array $details, string $method, Operator $operator, string $phone, ?string $deviceHash, ?int $variantId = null): GroupBuyParticipant
    {
        $campaign = $group->campaign()->with('product.seller')->first();

        if (! $group->isOpen() || ! $campaign->isOpen()) {
            throw new MarketplaceException('This group is closed. Start a new group instead.');
        }

        if ($campaign->product->seller->user_id === $user->id) {
            throw new MarketplaceException('You cannot join a group for your own product.');
        }

        if ($quantity < 1 || $quantity > $campaign->max_quantity_each) {
            throw new MarketplaceException("Each member can buy 1 to {$campaign->max_quantity_each}.");
        }

        if ($method === 'delivery' && ! $campaign->servesDistrict($details['district'] ?? null)) {
            throw new MarketplaceException('This group offer does not deliver to your area. Choose the collection point if there is one.');
        }

        // Another account on a phone already in this group does not count as a new member.
        if ($deviceHash && GroupBuyParticipant::where('group_buy_id', $group->id)->where('user_id', '!=', $user->id)
            ->whereIn('status', ['pending', 'paid'])->where('device_hash', $deviceHash)->exists()) {
            throw new MarketplaceException('This phone is already in the group with another account.');
        }

        return DB::transaction(function () use ($user, $group, $campaign, $quantity, $details, $method, $operator, $phone, $deviceHash, $variantId) {
            $locked = GroupBuy::lockForUpdate()->find($group->id);
            $taken = GroupBuyParticipant::where('group_buy_id', $locked->id)->whereIn('status', ['pending', 'paid'])->count();

            if ($campaign->max_group_size && $taken >= $campaign->max_group_size) {
                throw new MarketplaceException('This group is full. Start a new group instead.');
            }

            $existing = GroupBuyParticipant::where('group_buy_id', $locked->id)->where('user_id', $user->id)->first();

            if ($existing && in_array($existing->status, ['pending', 'paid'], true)) {
                throw new MarketplaceException($existing->status === 'paid' ? 'You are already in this group.' : 'Your place is waiting for payment. Approve it on your phone.');
            }

            try {
                $participant = $existing ?? GroupBuyParticipant::create(['group_buy_id' => $locked->id, 'user_id' => $user->id, 'quantity' => $quantity]);
            } catch (UniqueConstraintViolationException) {
                throw new MarketplaceException('You are already in this group.');
            }

            $checkout = $this->checkouts->place(
                $user,
                // Checkout checks the option belongs to the product, is on sale and has the stock.
                [['product_id' => $campaign->product_id, 'variant_id' => $variantId, 'quantity' => $quantity, 'unit_price' => (string) $campaign->group_price, 'is_group' => true]],
                $details,
                [$campaign->seller_id => $method],
                $operator,
                $phone,
                null,
                'app',
            );

            $participant->forceFill([
                'quantity' => $quantity,
                'status' => 'pending',
                'checkout_id' => $checkout->id,
                'order_id' => $checkout->orders->first()->id,
                'device_hash' => $deviceHash,
            ])->save();

            // A fully discounted order is paid at once; settle it now that the participant points at it.
            if ($checkout->status->value === 'paid') {
                $this->onCheckoutPaid($checkout);
            }

            return $participant->refresh();
        });
    }

    /** Called when a checkout is paid (inside the payment lock). */
    public function onCheckoutPaid(Checkout $checkout): void
    {
        $orderIds = Order::where('checkout_id', $checkout->id)->pluck('id');

        foreach (GroupBuyParticipant::whereIn('order_id', $orderIds)->get() as $participant) {
            $group = GroupBuy::lockForUpdate()->find($participant->group_buy_id);
            $order = Order::find($participant->order_id);

            if ($participant->status !== 'paid') {
                $participant->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

                if ($group->status === GroupBuy::OPEN) {
                    $group->increment('paid_count');
                }
            }

            match (true) {
                // Paid after the group failed: refund straight away.
                $group->status === GroupBuy::FAILED => $this->refundMember($participant),
                $group->status === GroupBuy::OPEN && $group->paid_count >= $group->campaign->min_group_size => $this->succeed($group),
                // Still filling: the seller waits (no deadline to accept yet).
                $group->status === GroupBuy::OPEN => $order->forceFill(['accept_by' => null])->save(),
                default => null,
            };
        }
    }

    /** The group has enough paid members: the seller can now accept every order. */
    private function succeed(GroupBuy $group): void
    {
        $group->forceFill(['status' => GroupBuy::SUCCEEDED, 'completed_at' => now()])->save();

        $paid = $group->participants()->where('status', 'paid')->with('order', 'user')->get();

        foreach ($paid as $participant) {
            $participant->order?->forceFill(['accept_by' => now()->addHours(config('wibuy.accept_within_hours'))])->save();
            $this->sms->send($participant->user->phone, "WiBuy: Your group for {$group->campaign->product->title} is complete! The seller is preparing your order. ".config('wibuy.tagline'));
        }

        $this->sms->send($group->campaign->seller->user->phone, "WiBuy: A group of {$paid->count()} for {$group->campaign->product->title} is complete. Accept the orders in your dashboard. ".config('wibuy.tagline'));
    }

    /** Close groups whose time ran out without enough paid members. Safe to run again. */
    public function expireDue(): int
    {
        $count = 0;

        GroupBuy::where('status', GroupBuy::OPEN)->where('expires_at', '<=', now())->pluck('id')->each(function (int $id) use (&$count) {
            $failed = DB::transaction(function () use ($id) {
                $group = GroupBuy::lockForUpdate()->find($id);

                if ($group->status !== GroupBuy::OPEN || $group->expires_at->isFuture()) {
                    return null;
                }

                $group->forceFill(['status' => GroupBuy::FAILED, 'completed_at' => now()])->save();

                return $group;
            });

            if (! $failed) {
                return;
            }

            $count++;

            foreach ($failed->participants()->with('user', 'order')->get() as $participant) {
                if ($participant->status === 'paid') {
                    $this->refundMember($participant);
                } elseif ($participant->status === 'pending' && $participant->checkout_id) {
                    // Also marks the member cancelled. If the payment landed meanwhile, the checkout
                    // is no longer pending and onCheckoutPaid refunds it because the group failed.
                    $this->checkouts->cancelUnpaid(Checkout::find($participant->checkout_id));
                }
            }
        });

        return $count;
    }

    private function refundMember(GroupBuyParticipant $participant): void
    {
        $order = $participant->order()->first();

        if ($order && $order->status === OrderStatus::Paid) {
            $this->orders->cancelFailedGroup($order);
        }

        $participant->forceFill(['status' => 'refunded'])->save();
    }

    /**
     * What the app shows: progress, time left and members by first name only (never addresses).
     *
     * @return array<string, mixed>
     */
    public function present(GroupBuy $group, ?User $viewer): array
    {
        $campaign = $group->campaign;
        $members = $group->participants()->where('status', 'paid')->with('user')->get();

        return [
            'token' => $group->token,
            'status' => $group->isOpen() || $group->status !== GroupBuy::OPEN ? $group->status : 'expiring',
            'paid_count' => $group->paid_count,
            'min_group_size' => $campaign->min_group_size,
            'needed' => max(0, $campaign->min_group_size - $group->paid_count),
            'expires_at' => $group->expires_at->toIso8601String(),
            'members' => $members->map(fn ($p) => $p->user->first_name)->values(),
            'my_status' => $viewer ? $group->participants()->where('user_id', $viewer->id)->value('status') : null,
            'is_leader' => $viewer?->id === $group->leader_id,
            'group_price' => (string) $campaign->group_price,
            'normal_price' => (string) $campaign->product->price,
            'saving_per_unit' => $campaign->savingPerUnit(),
            'share_url' => route('groups.show', $group),
        ];
    }

    /** Seller and admin view: how the offer performs. */
    public function stats(GroupBuyCampaign $campaign): array
    {
        $groups = $campaign->groups();

        return [
            'groups' => (clone $groups)->count(),
            'succeeded' => (clone $groups)->where('status', GroupBuy::SUCCEEDED)->count(),
            'failed' => (clone $groups)->where('status', GroupBuy::FAILED)->count(),
            'open' => (clone $groups)->where('status', GroupBuy::OPEN)->count(),
            'units_sold' => (int) GroupBuyParticipant::whereIn('group_buy_id', $campaign->groups()->where('status', GroupBuy::SUCCEEDED)->select('id'))
                ->where('status', 'paid')->sum('quantity'),
            'revenue' => Money::from(GroupBuyParticipant::whereIn('group_buy_id', $campaign->groups()->where('status', GroupBuy::SUCCEEDED)->select('id'))
                ->where('status', 'paid')->sum('quantity') * (float) $campaign->group_price),
        ];
    }
}
