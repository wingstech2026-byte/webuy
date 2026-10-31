<?php

namespace Database\Factories;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Seller;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'seller_id' => Seller::factory(),
            'category_id' => null,
            'title' => ucfirst($title),
            'slug' => Slug::unique(Product::class, $title),
            'description' => fake()->paragraph(),
            'price' => '100.00',
            'compare_at_price' => null,
            'stock' => 50,
            'unit' => 'piece',
            'min_order_quantity' => 1,
            'condition' => ProductCondition::New,
            'status' => ProductStatus::Active,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Draft]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Suspended]);
    }

    public function priced(string $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }

    public function stock(int $stock): static
    {
        return $this->state(fn () => ['stock' => $stock]);
    }

    /** Wholesale tiers: min quantity => unit price. Default: 10+ units 90.00, 50+ units 80.00. */
    public function withTiers(array $tiers = [10 => '90.00', 50 => '80.00']): static
    {
        return $this->afterCreating(function (Product $product) use ($tiers) {
            foreach ($tiers as $min => $price) {
                PriceTier::create(['product_id' => $product->id, 'min_quantity' => $min, 'unit_price' => $price]);
            }
        });
    }
}
