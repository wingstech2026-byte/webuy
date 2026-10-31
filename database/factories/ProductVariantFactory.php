<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->unique()->randomElement(['Red', 'Blue', 'Black', 'White', 'Green']).', '.fake()->randomElement(['S', 'M', 'L', 'XL']),
            'price' => null,
            'stock' => 5,
            'is_active' => true,
        ];
    }
}
