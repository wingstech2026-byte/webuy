<?php

namespace App\Services\Sms;

use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\GeoService;
use App\Services\SearchService;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Ordering without data or a smartphone: by SMS ("BUY 123 2 ORANGE") or the USSD menu. Each product's code is
 * its number on WiBuy. Payment is the same mobile money prompt as on the website, so the money rules stay the same:
 * the shop is paid only after the buyer gets the order.
 */
class SmsOrdering
{
    public function __construct(private CheckoutService $checkouts, private SearchService $search, private GeoService $geo) {}

    public static function enabled(): bool
    {
        return (bool) config('services.sms_ordering.enabled');
    }

    /** The answer to one text message from $phone (already normalised, e.g. 23276123456). */
    public function reply(string $phone, string $text): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $command = Str::upper($words[0] ?? '');
        $rest = trim(Str::after(trim($text), $words[0] ?? ''));

        return match ($command) {
            'JOIN' => $this->join($phone, $rest),
            'FIND' => $this->find($rest),
            'BUY' => $this->buyFromText($phone, array_slice($words, 1)),
            'STATUS' => $this->status($phone),
            default => $this->help(),
        };
    }

    public function help(): string
    {
        return 'WiBuy by SMS: FIND rice (see products and codes), BUY 123 2 ORANGE (code, how many, ORANGE or AFRI), STATUS (your last order). New? JOIN your name, your area, your district.';
    }

    /** "JOIN Aminata Kamara, Lumley, Western Area Urban": an account (or the missing details) for this phone. */
    public function join(string $phone, string $details): string
    {
        $parts = array_map('trim', explode(',', $details));

        if (count($parts) < 3 || mb_strlen($parts[0]) < 3 || $parts[1] === '') {
            return 'Send: JOIN your full name, your area, your district. Example: JOIN Aminata Kamara, Lumley, Western Area Urban';
        }

        $district = $this->district($parts[2]);

        if (! $district) {
            return 'We do not know the district "'.mb_substr($parts[2], 0, 30).'". Try e.g. Western Area Urban, Bo, Kenema, Makeni (Bombali), Kono.';
        }

        $user = User::firstOrNew(['phone' => $phone]);
        $user->forceFill(['name' => mb_substr($parts[0], 0, 80), 'area' => mb_substr($parts[1], 0, 80), 'district' => $district, 'signup_channel' => $user->exists ? $user->signup_channel : 'sms'])->save();

        return "Welcome {$parts[0]}! You can now order: text FIND rice to see products, then BUY code how-many ORANGE (or AFRI).";
    }

    public function find(string $words): string
    {
        if (mb_strlen($words) < 2) {
            return 'Send: FIND and what you want, e.g. FIND rice 50kg';
        }

        $products = collect($this->search->search(['q' => mb_substr($words, 0, 60)], 3)->items());

        return $products->isEmpty()
            ? 'Nobody sells "'.mb_substr($words, 0, 40).'" on WiBuy right now. Try other words.'
            : $products->map(fn (Product $p) => "#{$p->id} {$p->title} ".Money::format($p->price).($p->unit ? "/{$p->unit}" : '')." ({$p->seller->shop_name})")->implode('; ')
                .'. To order: BUY code how-many ORANGE (or AFRI).';
    }

    /** @param list<string> $args code, how many, operator */
    private function buyFromText(string $phone, array $args): string
    {
        $operator = collect($args)->map(fn ($w) => self::operator($w))->filter()->first();
        $numbers = collect($args)->filter(fn ($w) => ctype_digit(ltrim($w, '#')))->map(fn ($w) => (int) ltrim($w, '#'))->values();

        if ($numbers->isEmpty()) {
            return 'Send: BUY code how-many ORANGE (or AFRI). Example: BUY 123 2 ORANGE. Find codes with FIND rice.';
        }

        if (! $operator) {
            return 'Add how you pay: BUY '.$numbers[0].' '.($numbers[1] ?? 1).' ORANGE (Orange Money) or AFRI (Afrimoney).';
        }

        return $this->buy($phone, $numbers[0], $numbers[1] ?? 1, $operator);
    }

    /** Places the order and sends the mobile money prompt to this phone. Used by SMS and USSD. */
    public function buy(string $phone, int $productId, int $quantity, Operator $operator): string
    {
        $user = User::where('phone', $phone)->first();

        if (! $user?->hasBuyerDetails()) {
            return 'First tell us who you are: JOIN your full name, your area, your district. Example: JOIN Aminata Kamara, Lumley, Western Area Urban';
        }

        $product = Product::with('seller')->find($productId);

        if (! $product || ! $product->isListed()) {
            return "There is no product with code {$productId}. Find codes with FIND rice.";
        }

        if ($product->hasVariants() || $product->addons()->exists()) {
            return "{$product->title} has options to choose. Please order it on the WiBuy website or app.";
        }

        $quantity = max($quantity, $product->min_order_quantity, 1);
        // Brought to the buyer's area when the shop delivers there, otherwise collected at the shop.
        $method = $this->geo->deliveryQuote($product->seller, $user->district)['can_deliver'] ? 'delivery' : 'pickup';

        try {
            $checkout = $this->checkouts->place(
                $user,
                [['product_id' => $product->id, 'quantity' => $quantity]],
                ['name' => $user->name, 'phone' => $user->phone, 'district' => $user->district, 'address' => $user->area.', '.$user->district, 'note' => 'Ordered by SMS'],
                [$product->seller_id => $method],
                $operator,
                $phone,
                null,
                'sms',
            );
        } catch (MarketplaceException $e) {
            return $e->getMessage();
        }

        $order = $checkout->orders()->first();

        return "Order {$order->number}: {$quantity} x {$product->title} = ".Money::format($checkout->total)
            .($method === 'delivery' ? ' with delivery to '.$user->area : ', collect at '.($product->seller->pickup_address ?: $product->seller->shop_name))
            .". Approve the {$operator->label()} prompt on your phone now. Reply STATUS any time.";
    }

    public function status(string $phone): string
    {
        $order = Order::whereHas('buyer', fn ($q) => $q->where('phone', $phone))->where('status', '!=', OrderStatus::PendingPayment)->latest('id')->first();

        return $order
            ? "Order {$order->number} from {$order->seller->shop_name}: {$order->statusLabel()}. Total ".Money::format($order->total).'.'
            : 'You have no paid orders yet. Send HELP to see how to order.';
    }

    public static function operator(string $word): ?Operator
    {
        return match (Str::upper(trim($word))) {
            'ORANGE', 'OM', 'O' => Operator::Orange,
            'AFRI', 'AFRIMONEY', 'AFRICELL', 'A' => Operator::Afrimoney,
            default => null,
        };
    }

    /** "western urban", "bo", "Makeni" (the main town) -> a district name. */
    private function district(string $text): ?string
    {
        $text = Str::lower(trim($text));
        $towns = ['freetown' => 'Western Area Urban', 'waterloo' => 'Western Area Rural', 'makeni' => 'Bombali', 'koidu' => 'Kono', 'kabala' => 'Koinadugu', 'magburaka' => 'Tonkolili', 'lunsar' => 'Port Loko'];

        return $towns[$text] ?? collect(config('wibuy.districts'))->first(fn ($d) => Str::lower($d) === $text)
            ?? collect(config('wibuy.districts'))->first(fn ($d) => str_contains(Str::lower($d), $text) || str_contains($text, Str::lower($d)));
    }
}
