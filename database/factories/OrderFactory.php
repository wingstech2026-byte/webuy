<?php

namespace Database\Factories;

use App\Enums\CheckoutStatus;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A ready-made order for tests: one item, 100.00 subtotal, 5% commission. Use ->paid(), ->status(...) etc.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'checkout_id' => Checkout::factory(),
            'buyer_id' => fn (array $attrs) => Checkout::find($attrs['checkout_id'])->buyer_id,
            'seller_id' => Seller::factory(),
            'status' => OrderStatus::PendingPayment,
            'subtotal' => '100.00',
            'delivery_fee' => '0.00',
            'commission' => '5.00',
            'commission_percent' => '5.00',
            'total' => '100.00',
            'delivery_method' => DeliveryMethod::Pickup,
            'recipient_name' => fake()->name(),
            'recipient_phone' => fake()->numerify('23276######'),
            'stock_reserved' => true,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Order $order) {
            if ($order->items()->doesntExist()) {
                $product = Product::factory()->create(['seller_id' => $order->seller_id]);

                OrderItem::create([
                    'order_id' => $order->id, 'product_id' => $product->id, 'title' => $product->title,
                    'unit' => $product->unit, 'unit_price' => $order->subtotal, 'quantity' => 1, 'line_total' => $order->subtotal,
                ]);
            }
        });
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(function () use ($status) {
            $attrs = ['status' => $status, 'expires_at' => null];

            if ($status !== OrderStatus::PendingPayment) {
                $attrs['paid_at'] = now();
                $attrs['accept_by'] = now()->addHours(24);
            }

            return $attrs;
        });
    }

    public function paid(): static
    {
        return $this->status(OrderStatus::Paid)->afterCreating(
            fn (Order $order) => Checkout::whereKey($order->checkout_id)->update(['status' => CheckoutStatus::Paid, 'paid_at' => now()])
        );
    }

    public function forBuyer(User $buyer): static
    {
        return $this->state(fn () => ['checkout_id' => Checkout::factory()->create(['buyer_id' => $buyer->id])->id]);
    }
}
