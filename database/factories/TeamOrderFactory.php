<?php

namespace Database\Factories;

use App\Models\Seller;
use App\Models\TeamOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamOrder>
 */
class TeamOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'token' => Str::lower(Str::random(10)),
            'leader_id' => User::factory(),
            'seller_id' => Seller::factory(),
            'title' => 'Lunch at the office',
            'closes_at' => now()->addHour(),
            'recipient_name' => fake()->name(),
            'recipient_phone' => fake()->numerify('23276######'),
            'district' => 'Western Area Urban',
            'address' => '12 Siaka Stevens Street',
            'building' => 'Africell House',
            'floor' => '3',
            'latitude' => 8.4840,
            'longitude' => -13.2299,
            'status' => TeamOrder::OPEN,
        ];
    }
}
