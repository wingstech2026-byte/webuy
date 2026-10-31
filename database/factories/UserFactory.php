<?php

namespace Database\Factories;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('23276######'),
            'is_admin' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['is_admin' => true]);
    }

    /** A buyer who also has an approved shop. */
    public function seller(array $shop = []): static
    {
        return $this->afterCreating(function (User $user) use ($shop) {
            Seller::factory()->create(['user_id' => $user->id] + $shop);
        });
    }
}
