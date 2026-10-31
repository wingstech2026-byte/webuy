<?php

namespace Database\Factories;

use App\Enums\CheckoutStatus;
use App\Enums\Operator;
use App\Models\Checkout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checkout>
 */
class CheckoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'buyer_id' => User::factory(),
            'total' => '100.00',
            'status' => CheckoutStatus::PendingPayment,
            'payer_phone' => fake()->numerify('23276######'),
            'operator' => Operator::Orange,
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
