<?php

namespace App\Services;

use App\Enums\Operator;
use App\Exceptions\MarketplaceException;
use App\Models\ImportOrder;
use App\Models\ImportProduct;
use App\Models\Payment;
use App\Models\User;
use App\Sms\SmsMessages;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * WiBuy Global (behind WIBUY_GLOBAL_ENABLED): products sourced from China with landed prices by sea or air.
 * The buyer pays a deposit now and the balance on arrival; WiBuy updates the tracking steps.
 */
class ImportService
{
    /** Steps the admin moves an order through, after the deposit. */
    public const TRACKING = ['ordered', 'at_factory', 'shipped', 'at_port', 'customs_cleared', 'ready_for_pickup', 'completed'];

    public function __construct(private ChargeService $charges, private SmsService $sms) {}

    public static function enabled(): bool
    {
        return (bool) config('wibuy.features.global');
    }

    /** @throws MarketplaceException */
    public function order(User $user, ImportProduct $product, int $quantity, string $shipping, Operator $operator, string $phone): ImportOrder
    {
        $this->ensureEnabled();
        $option = $product->shippingOptions()[$shipping] ?? null;

        if (! $product->is_active || ! $option) {
            throw new MarketplaceException('This import option is not available.');
        }

        if ($quantity < $product->min_quantity) {
            throw new MarketplaceException("The minimum order is {$product->min_quantity} {$product->unit}.");
        }

        $total = Money::mul($option['price'], $quantity);
        $deposit = Money::percentOf($total, $product->deposit_percent);

        $order = ImportOrder::create([
            'number' => 'WG-'.Str::upper(Str::random(8)),
            'user_id' => $user->id,
            'import_product_id' => $product->id,
            'quantity' => $quantity,
            'shipping' => $shipping,
            'unit_price' => $option['price'],
            'total' => $total,
            'deposit_amount' => $deposit,
            'balance_amount' => Money::sub($total, $deposit),
            'status' => 'awaiting_deposit',
            'estimated_arrival' => now()->addDays($option['days'])->toDateString(),
            'timeline' => [],
        ]);

        $this->charges->create($order, $deposit, $operator, $phone);

        return $order;
    }

    /** @throws MarketplaceException */
    public function payBalance(User $user, ImportOrder $order, Operator $operator, string $phone): Payment
    {
        $this->ensureEnabled();

        if ($order->user_id !== $user->id) {
            throw new MarketplaceException('This is not your order.');
        }

        if ($order->balance_paid_at || ! in_array($order->status, ['at_port', 'customs_cleared', 'ready_for_pickup'], true)) {
            throw new MarketplaceException('The balance is paid when your goods arrive in Freetown.');
        }

        return $this->charges->create($order, (string) $order->balance_amount, $operator, $phone);
    }

    /** A deposit or balance charge completed (inside the payment lock). */
    public function paid(ImportOrder $order, Payment $payment): void
    {
        if (! $order->deposit_paid_at) {
            $order->forceFill(['deposit_paid_at' => now()])->save();
            $this->advance($order, 'ordered');

            return;
        }

        if (! $order->balance_paid_at) {
            $order->forceFill(['balance_paid_at' => now()])->save();
        }
    }

    /** The admin moves the order to its next tracking step; the buyer gets an SMS. */
    public function advance(ImportOrder $order, string $status): void
    {
        if (! in_array($status, self::TRACKING, true)) {
            throw new MarketplaceException('Unknown step.');
        }

        if ($status === 'completed' && ! $order->balance_paid_at) {
            throw new MarketplaceException('The balance is not paid yet.');
        }

        $timeline = $order->timeline ?? [];
        $timeline[$status] = now()->toIso8601String();
        $order->forceFill(['status' => $status, 'timeline' => $timeline])->save();

        $this->sms->send($order->user->phone, SmsMessages::importUpdate($order));
    }

    private function ensureEnabled(): void
    {
        if (! self::enabled()) {
            throw new MarketplaceException('WiBuy Global is not switched on.');
        }
    }
}
