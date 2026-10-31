<?php

namespace Database\Factories;

use App\Models\Category;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'parent_id' => null,
            'name' => ucfirst($name),
            'slug' => Slug::unique(Category::class, $name),
            'emoji' => '📦',
            'sort_order' => 0,
        ];
    }
}
