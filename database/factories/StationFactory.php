<?php

namespace Database\Factories;

use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Station> */
class StationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->lastName().' Mini Mart (WiBuy station)',
            'address' => fake()->streetAddress().', Freetown',
            'district' => 'Western Area Urban',
            'latitude' => fake()->latitude(8.40, 8.50),
            'longitude' => fake()->longitude(-13.30, -13.15),
            'opening_hours' => 'Mon–Sat 8am–8pm',
            'is_active' => true,
        ];
    }
}
