<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\LiveSession;
use App\Models\WishlistItem;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Live selling: schedule with a YouTube/Facebook link, go live (followers told), pin a product, comments, replay. */
class LiveSellingTest extends TestCase
{
    use Marketplace;

    public function test_a_shop_goes_live_viewers_comment_and_see_the_pinned_product_then_it_is_a_replay(): void
    {
        $phone = $this->makeProduct(null, ['title' => 'Tecno Spark 20']);
        $seller = $phone->seller;
        $pastBuyer = $this->paidOrder($this->makeProduct($seller))->buyer;
        $saver = $this->buyer();
        WishlistItem::create(['user_id' => $saver->id, 'product_id' => $phone->id]);
        $viewer = $this->buyer(['name' => 'Fatmata Sesay']);

        $this->actingAs($seller->user)->post(route('seller.live.store'), ['title' => 'New phones', 'stream_url' => 'https://evil.example/live', 'products' => [$phone->id]])
            ->assertSessionHasErrors('general');
        $this->actingAs($seller->user)->post(route('seller.live.store'), ['title' => 'New phones', 'stream_url' => 'https://youtube.com/live/abcDEF12345', 'products' => [$phone->id]])
            ->assertSessionHas('status');
        $show = LiveSession::sole();
        $this->assertSame('https://www.youtube.com/embed/abcDEF12345?autoplay=1&playsinline=1', $show->embed_url);

        $this->actingAs($viewer)->postJson(route('live.comment', $show), ['body' => 'Hello'])->assertStatus(422); // not live yet

        $this->actingAs($seller->user)->post(route('seller.live.act', [$show, 'start']))->assertSessionHas('status');
        $this->assertSame(2, AppNotification::whereIn('user_id', [$pastBuyer->id, $saver->id])->where('title', "🔴 {$seller->shop_name} is live")->count());
        $this->actingAs($seller->user)->post(route('seller.live.act', [$show, 'pin']), ['product' => $phone->id])->assertSessionHas('status');

        $this->actingAs($viewer)->postJson(route('live.comment', $show), ['body' => 'Is it 128GB?'])->assertCreated()
            ->assertJsonPath('comments.0.name', 'Fatmata')->assertJsonPath('pinned.title', 'Tecno Spark 20');
        $this->actingAs($viewer)->postJson(route('live.comment', $show), ['body' => 'Call me 076 123 456'])->assertStatus(422);

        $this->get(route('live.show', $show))->assertOk()->assertSee('youtube.com/embed/abcDEF12345')->assertSee('Is it 128GB?')->assertSee('Showing now');
        $this->get(route('live.index'))->assertSee('Live now')->assertSee('New phones');
        $this->getJson(route('live.feed', ['liveSession' => $show, 'after' => 999999]))->assertJsonCount(0, 'comments');

        $this->actingAs($seller->user)->post(route('seller.live.act', [$show, 'end']));
        $this->get(route('live.index'))->assertSee('Replays');
        $this->actingAs($viewer)->postJson(route('live.comment', $show), ['body' => 'Late'])->assertStatus(422);
    }

    public function test_the_app_lists_shows_and_comments(): void
    {
        $phone = $this->makeProduct();
        $show = LiveSession::create(['seller_id' => $phone->seller_id, 'title' => 'Facebook show', 'stream_url' => 'https://www.facebook.com/shop/videos/123', 'status' => LiveSession::LIVE]);
        $show->products()->attach($phone);

        $this->getJson('/api/v1/live')->assertJsonPath('data.0.title', 'Facebook show')->assertJsonPath('data.0.status', 'live');
        $this->getJson("/api/v1/live/{$show->id}")->assertJsonPath('products.0.id', $phone->id);
        $this->assertStringStartsWith('https://www.facebook.com/plugins/video.php?href=', $show->embed_url);

        Sanctum::actingAs($this->buyer());
        $this->postJson("/api/v1/live/{$show->id}/comments", ['body' => 'Price?'])->assertCreated()->assertJsonPath('comments.0.body', 'Price?');
    }
}
