<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Coupon;
use App\Models\LiveSession;
use App\Models\User;
use App\Services\LiveSellingService;
use App\Services\SellerReportService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Following shops and their feed, rewards when an invited friend's first order arrives, seller analytics and stock forecast. */
class FollowReferralAnalyticsTest extends TestCase
{
    use Marketplace;

    public function test_a_buyer_follows_a_shop_and_hears_about_new_products_offers_and_lives(): void
    {
        $seller = $this->makeSeller();
        $fan = $this->buyer();

        $this->actingAs($fan)->post(route('shops.follow', $seller))->assertSessionHas('status');
        $this->actingAs($fan)->get(route('shops.show', $seller))->assertSee('1 follower(s)')->assertSee('✓ Following');
        $this->actingAs($seller->user)->post(route('shops.follow', $seller))->assertSessionHasErrors('general'); // own shop

        // New products: told once a day per shop.
        $this->makeProduct($seller, ['title' => 'Fresh fish']);
        $this->makeProduct($seller, ['title' => 'Dried fish']);
        $this->actingAs($seller->user)->post(route('seller.offers.store'), ['kind' => 'spend', 'amount' => 10, 'min_spend' => 100]);
        $live = LiveSession::create(['seller_id' => $seller->id, 'title' => 'Fish market live', 'stream_url' => 'https://youtube.com/live/abcdef123', 'status' => LiveSession::SCHEDULED]);
        app(LiveSellingService::class)->start($live);

        $titles = AppNotification::where('user_id', $fan->id)->orderBy('id')->pluck('title')->all();
        $this->assertSame(["🆕 New from {$seller->shop_name}", "🏷 {$seller->shop_name}: new offer", "🔴 {$seller->shop_name} is live"], $titles);

        $this->actingAs($fan)->get(route('following.index'))->assertSee('Fresh fish')->assertSee('Le 10.00 off orders over Le 100.00')->assertSee('Fish market live');
        Sanctum::actingAs($fan);
        $this->getJson('/api/v1/following')->assertJsonCount(2, 'products')->assertJsonPath('live.0.title', 'Fish market live');
        $this->getJson("/api/v1/shops/{$seller->slug}")->assertJsonPath('followers', 1)->assertJsonPath('is_following', true);
        $this->postJson("/api/v1/shops/{$seller->slug}/follow")->assertJsonPath('is_following', false)->assertJsonPath('followers', 0);
    }

    public function test_an_invited_friend_and_the_inviter_both_get_a_coupon_when_the_first_order_arrives_once(): void
    {
        $inviter = $this->buyer(['name' => 'Aminata']);
        $this->actingAs($inviter)->get(route('invite.show'))->assertOk()->assertSee('?ref=');
        $friend = $this->buyer(['name' => 'Musa', 'referred_by_id' => $inviter->id]);

        $order = $this->shippedOrder(null, $friend);
        $this->orders()->confirmDelivery($order, $friend);
        $second = $this->shippedOrder(null, $friend);
        $this->orders()->confirmDelivery($second, $friend);

        $this->assertSame(1, Coupon::where('user_id', $inviter->id)->where('source', 'referral')->count());
        $this->assertSame(1, Coupon::where('user_id', $friend->id)->where('source', 'referral')->count());
        $this->assertSame('🎁 Your friend ordered!', AppNotification::where('user_id', $inviter->id)->latest('id')->value('title'));

        Sanctum::actingAs($inviter);
        $this->getJson('/api/v1/invite')->assertJsonPath('data.joined', 1)->assertJsonPath('data.ordered', 1);
    }

    public function test_the_shop_sees_viewers_carts_orders_best_hours_and_when_stock_runs_out(): void
    {
        $this->travelTo(now()->setTime(13, 15));
        $seller = $this->makeSeller();
        $rice = $this->makeProduct($seller, ['title' => 'Rice', 'stock' => 20]);
        $oil = $this->makeProduct($seller, ['title' => 'Oil', 'stock' => 500]);

        foreach (User::factory()->count(4)->create() as $viewer) {
            DB::table('product_views')->insert(['user_id' => $viewer->id, 'product_id' => $rice->id, 'viewed_at' => now()]);
        }
        $this->paidOrder($rice, null, 7); // 14 rice sold in the last 14 days: 1 a day, 6 left
        $this->paidOrder($rice, null, 7);
        $this->paidOrder($oil, null, 1);

        $report = app(SellerReportService::class)->report($seller->fresh());

        $riceRow = collect($report['funnel'])->firstWhere('title', 'Rice');
        $this->assertSame(['views' => 4, 'orders' => 2, 'conversion' => 50.0], ['views' => $riceRow['views'], 'orders' => $riceRow['orders'], 'conversion' => $riceRow['conversion']]);
        $this->assertSame(3, $report['hours'][13]);
        $this->assertSame([['title' => 'Rice', 'days_left' => 6]], collect($report['forecast'])->map(fn ($r) => ['title' => $r['title'], 'days_left' => $r['days_left']])->all()); // 6 left at 1 a day; oil lasts long

        $this->actingAs($seller->user)->get(route('seller.reports'))->assertSee('Will run out soon')->assertSee('about 6 day(s) left')->assertSee('Most orders come around');
    }
}
