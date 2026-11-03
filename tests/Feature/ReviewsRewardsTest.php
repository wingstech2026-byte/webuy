<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Coupon;
use App\Models\PointTransaction;
use App\Models\Review;
use App\Models\SellerLocation;
use App\Services\CouponService;
use App\Services\PointsService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Food reviews (taste, portion, packaging), shops answering reviews, top rated near you, and "My rewards". */
class ReviewsRewardsTest extends TestCase
{
    use Marketplace;

    public function test_a_food_review_has_taste_portion_and_packaging_and_the_shop_answers_it(): void
    {
        $shop = $this->makeSeller(['Western Area Urban' => '20.00'], ['is_instant' => true]);
        $dish = $this->makeProduct($shop, ['title' => 'Jollof rice']);
        $order = $this->shippedOrder($dish);
        $this->orders()->confirmDelivery($order, $order->buyer);
        $item = $order->items()->sole();

        $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertSee('😋 Taste');
        $this->actingAs($order->buyer)->post(route('orders.review', $order), ['items' => [$item->id => ['rating' => 4, 'taste' => 5, 'portion' => 3, 'packaging' => 4, 'comment' => 'Tasty!']]])
            ->assertSessionHasNoErrors();
        $this->get(route('products.show', $dish))->assertSee('😋 Taste 5/5')->assertSee('🍽 Portion 3/5');

        $review = Review::sole();
        $this->actingAs($this->makeSeller()->user)->post(route('seller.reviews.reply', ['kind' => 'product', 'id' => $review->id]), ['reply' => 'Hi'])->assertSessionHasErrors('reply');
        $this->actingAs($shop->user)->post(route('seller.reviews.reply', ['kind' => 'product', 'id' => $review->id]), ['reply' => 'Thank you! Bigger plates next week.'])->assertSessionHas('status');

        $this->get(route('products.show', $dish))->assertSee('Reply from the shop:')->assertSee('Bigger plates next week.');
        $this->assertStringContainsString('answered your review', AppNotification::where('user_id', $order->buyer_id)->latest('id')->value('title'));
        $this->getJson("/api/v1/products/{$dish->slug}")->assertJsonPath('food_scores.taste', 5)->assertJsonPath('reviews.0.seller_reply', 'Thank you! Bigger plates next week.');
    }

    public function test_top_rated_dishes_near_you_come_first_on_instant(): void
    {
        $shop = $this->makeSeller(['Western Area Urban' => '20.00'], ['is_instant' => true]);
        SellerLocation::create(['seller_id' => $shop->id, 'type' => 'shop', 'public_name' => 'x', 'district' => 'Western Area Urban', 'latitude' => 8.4930, 'longitude' => -13.2299, 'is_active' => true, 'delivery_enabled' => true, 'delivery_radius_km' => 5]);
        $this->makeProduct($shop, ['title' => 'Best cassava leaf', 'rating_avg' => 4.8, 'rating_count' => 12]);
        $this->makeProduct($shop, ['title' => 'New dish', 'rating_avg' => 5, 'rating_count' => 1]); // too few ratings

        $this->get(route('instant.index', ['lat' => 8.4840, 'lng' => -13.2299]))->assertSee('Top rated near you')->assertSeeInOrder(['Top rated', 'Best cassava leaf']);
        $this->getJson('/api/v1/instant?lat=8.4840&lng=-13.2299')->assertJsonCount(1, 'top_rated')->assertJsonPath('top_rated.0.title', 'Best cassava leaf');
    }

    public function test_my_rewards_shows_points_coupons_what_ends_soon_and_changes_points_for_a_coupon(): void
    {
        $buyer = $this->buyer();
        app(PointsService::class)->award($buyer, 600, 'Daily check-in');
        app(CouponService::class)->issue($buyer, ['title' => 'Welcome', 'source' => 'welcome', 'amount' => '10.00', 'expires_at' => now()->addDays(3)]);
        app(CouponService::class)->issue($buyer, ['title' => 'Spin prize', 'source' => 'spin', 'amount' => '5.00', 'expires_at' => now()->addDays(20)]);

        $this->actingAs($buyer)->get(route('rewards.mine'))->assertOk()->assertSee('600')->assertSee('Ends soon')->assertSee('Daily check-in')->assertSee('Change points for a coupon');
        $this->actingAs($buyer)->post(route('rewards.exchange'))->assertSessionHas('status');
        $this->assertSame(100, $buyer->fresh()->points_balance);
        $this->assertSame(-500, PointTransaction::where('user_id', $buyer->id)->latest('id')->value('points'));

        Sanctum::actingAs($buyer->fresh());
        $this->getJson('/api/v1/my-rewards')->assertJsonPath('points', 100)->assertJsonCount(1, 'expiring_soon')->assertJsonCount(3, 'usable');

        // The day it is 3 days away: one reminder; the next day it is not sent again.
        $this->artisan('wibuy:expiring-rewards')->expectsOutputToContain('Reminded 1')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('wibuy:expiring-rewards')->expectsOutputToContain('Reminded 0')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $buyer->id)->where('title', '⏰ Coupon ends soon')->count());
        $this->assertSame(1, Coupon::where('user_id', $buyer->id)->where('source', 'points')->count());
    }
}
