<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** "For you" from what the shopper looked at and bought; "People also bought" from other buyers. */
class RecommendationTest extends TestCase
{
    use Marketplace;

    public function test_for_you_follows_what_the_shopper_looks_at_and_skips_what_they_bought(): void
    {
        $phones = Category::factory()->create(['name' => 'Phones']);
        $food = Category::factory()->create(['name' => 'Food']);
        $tecno = $this->makeProduct(null, ['title' => 'Tecno Spark', 'category_id' => $phones->id]);
        $itel = $this->makeProduct(null, ['title' => 'Itel A70', 'category_id' => $phones->id, 'sold_count' => 50]);
        $samsung = $this->makeProduct(null, ['title' => 'Samsung A15', 'category_id' => $phones->id, 'sold_count' => 10]);
        $this->makeProduct(null, ['title' => 'Rice 50kg', 'category_id' => $food->id, 'sold_count' => 999]);
        $buyer = $this->buyer();

        // Before looking at anything, the website shows no "For you".
        $this->actingAs($this->buyer())->get('/')->assertDontSee('For you');

        $this->actingAs($buyer)->get(route('products.show', $tecno))->assertOk();
        $this->assertSame(1, DB::table('product_views')->where('user_id', $buyer->id)->count());
        $this->paidOrder($samsung, $buyer); // bought: not suggested again

        $page = $this->actingAs($buyer)->get('/')->assertSee('For you');
        $titles = $page->viewData('forYou')->pluck('title')->all();
        $this->assertSame('Itel A70', $titles[0]); // same category, best seller first
        $this->assertNotContains('Samsung A15', $titles);
        $this->assertContains('Rice 50kg', $titles); // topped up with popular products

        // The app sends the phone's own list for guests; logged-in shoppers get theirs.
        auth()->forgetGuards();
        $this->getJson('/api/v1/home?viewed='.$tecno->slug)->assertOk()->assertJsonPath('for_you.0.title', 'Itel A70');
        $this->getJson('/api/v1/home')->assertJsonCount(0, 'for_you');
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/products/{$itel->slug}")->assertOk();
        $this->assertSame(2, DB::table('product_views')->where('user_id', $buyer->id)->count());
    }

    public function test_people_who_bought_this_also_bought(): void
    {
        $solar = $this->makeProduct(null, ['title' => 'Solar panel']);
        $battery = $this->makeProduct(null, ['title' => 'Deep cycle battery']);
        $cable = $this->makeProduct(null, ['title' => 'Cable 10m']);
        $unrelated = $this->makeProduct(null, ['title' => 'Lipstick']);

        foreach ([[$solar, $battery], [$solar, $battery], [$solar, $cable]] as [$a, $b]) {
            $buyer = $this->buyer();
            $this->paidOrder($a, $buyer);
            $this->paidOrder($b, $buyer);
        }
        $this->paidOrder($unrelated);

        $this->get(route('products.show', $solar))->assertSee('People who bought this also bought')
            ->assertViewHas('alsoBought', fn ($list) => $list->pluck('title')->all() === ['Deep cycle battery', 'Cable 10m']);
        $this->getJson("/api/v1/products/{$solar->slug}")->assertJsonPath('also_bought.0.title', 'Deep cycle battery');
        $this->get(route('products.show', $unrelated))->assertDontSee('People who bought this also bought');
    }
}
