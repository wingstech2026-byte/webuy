<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    public function test_the_live_site_gets_an_admin_and_categories_but_no_demo_shops_products_or_reviews(): void
    {
        $this->seed(ProductionSeeder::class);
        $this->seed(ProductionSeeder::class);

        $this->assertSame(1, User::where('is_admin', true)->count());
        $this->assertGreaterThan(0, Category::whereNull('parent_id')->count());
        $this->assertSame(0, Seller::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Review::count());
    }
}
