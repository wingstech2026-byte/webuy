<?php

namespace Database\Factories;

use App\Enums\Operator;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Checkout;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'checkout_id' => Checkout::factory(),
            'kind' => PaymentKind::Deposit,
            'provider' => 'fake',
            'provider_reference' => (string) Str::uuid(),
            'phone' => fake()->numerify('23276######'),
            'operator' => Operator::Orange,
            'amount' => '100.00',
            'status' => PaymentStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Completed]);
    }
}
