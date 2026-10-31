<?php

namespace Database\Factories;

use App\Enums\Operator;
use App\Enums\SellerStatus;
use App\Models\Seller;
use App\Models\SellerDeliveryArea;
use App\Models\User;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seller>
 */
class SellerFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Shop';

        return [
            'user_id' => User::factory(),
            'shop_name' => $name,
            'slug' => Slug::unique(Seller::class, $name),
            'description' => fake()->sentence(),
            'district' => 'Western Area Urban',
            'area' => 'Kissy',
            'pickup_address' => fake()->streetAddress().', Freetown',
            'payout_phone' => fake()->numerify('23277######'),
            'payout_operator' => Operator::Orange,
            'status' => SellerStatus::Approved,
            'is_verified' => false,
            'offers_delivery' => true,
            'offers_pickup' => true,
            'approved_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => SellerStatus::Pending, 'approved_at' => null]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => SellerStatus::Rejected, 'approved_at' => null, 'rejection_reason' => 'Not enough information']);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['is_verified' => true]);
    }

    /** Delivers to the given districts (district => fee). */
    public function delivering(array $areas = ['Western Area Urban' => '20.00']): static
    {
        return $this->afterCreating(function (Seller $seller) use ($areas) {
            foreach ($areas as $district => $fee) {
                SellerDeliveryArea::create(['seller_id' => $seller->id, 'district' => $district, 'fee' => $fee]);
            }
        });
    }
}
