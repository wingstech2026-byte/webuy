<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Jobs\SendPushJob;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\Dispute;
use App\Models\Product;
use App\Models\Review;
use App\Models\Rfq;
use App\Models\TrialApplication;
use App\Models\TrialCampaign;
use App\Models\User;
use App\Services\ChatService;
use App\Services\FcmClient;
use App\Services\NotificationService;
use App\Services\OtpService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Seller tools, photos, chat and notifications in the app. */
class SellerChatNotificationApiTest extends TestCase
{
    use Marketplace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_only_approved_sellers_use_the_seller_tools(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->getJson('/api/v1/seller/dashboard')->assertForbidden();
    }

    public function test_a_seller_runs_orders_from_the_app(): void
    {
        $order = $this->paidOrder();
        $seller = $order->seller;
        Sanctum::actingAs($seller->user);

        $this->getJson('/api/v1/seller/dashboard')->assertOk()->assertJsonPath('counts.new', 1)->assertJsonPath('shop_name', $seller->shop_name);
        $this->getJson('/api/v1/seller/orders?tab=new')->assertOk()->assertJsonPath('data.0.id', $order->id);
        $this->getJson("/api/v1/seller/orders/{$order->id}")->assertJsonPath('can_accept', true);

        $this->postJson("/api/v1/seller/orders/{$order->id}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/v1/seller/orders/{$order->id}/ship", ['reason' => 'Ready at the shop'])->assertOk()->assertJsonPath('data.status', 'shipped');

        $other = $this->paidOrder();
        $this->postJson("/api/v1/seller/orders/{$other->id}/accept")->assertNotFound();
        $this->postJson("/api/v1/seller/orders/{$other->id}/reject")->assertNotFound();
    }

    public function test_a_seller_adds_and_edits_a_product_with_photos(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        $slug = $this->post('/api/v1/seller/products', [
            'title' => 'Bag of onions', 'price' => '45.00', 'stock' => 30, 'unit' => 'bag', 'min_order_quantity' => 1,
            'condition' => 'new', 'status' => 'active',
            'tiers' => [['min_quantity' => 10, 'unit_price' => '40.00', 'business_only' => '1']],
            'images' => [UploadedFile::fake()->image('onions.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.title', 'Bag of onions')->json('data.slug');

        $product = Product::where('slug', $slug)->firstOrFail();
        $this->assertSame(1, $product->images()->count());
        $this->assertTrue($product->tiers()->first()->business_only);

        $photoId = $product->images()->value('id');
        $this->post("/api/v1/seller/products/{$slug}", [
            'title' => 'Bag of red onions', 'price' => '50.00', 'stock' => 20, 'unit' => 'bag', 'min_order_quantity' => 1,
            'condition' => 'new', 'status' => 'active', 'remove_images' => [$photoId],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.title', 'Bag of red onions');
        $this->assertSame(0, $product->images()->count());

        $this->getJson('/api/v1/seller/products')->assertJsonPath('data.0.status', 'active');
    }

    public function test_a_seller_requests_a_payout_and_answers_a_request_for_quotes(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller);
        $rfq = Rfq::create(['buyer_id' => $this->buyer()->id, 'title' => 'Rice for a school', 'quantity' => 40, 'district' => 'Western Area Urban']);
        Sanctum::actingAs($seller->user);

        $this->postJson('/api/v1/seller/payouts', ['amount' => '500'])->assertUnprocessable();
        $this->getJson('/api/v1/seller/earnings')->assertOk()->assertJsonStructure(['money' => ['available'], 'min_payout', 'payouts']);

        $this->getJson('/api/v1/seller/rfqs')->assertJsonPath('data.0.title', 'Rice for a school');
        $this->postJson("/api/v1/seller/rfqs/{$rfq->id}/quote", ['product_id' => $product->id, 'unit_price' => '95', 'quantity' => 40, 'lead_time_days' => 2])->assertCreated();
        $this->getJson('/api/v1/seller/rfqs')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seller/quotes')->assertJsonPath('data.0.status', 'submitted');
    }

    public function test_buyers_report_a_problem_with_photos_from_the_app(): void
    {
        $order = $this->shippedOrder();
        Sanctum::actingAs($order->buyer);

        $this->getJson('/api/v1/problem-reasons')->assertJsonPath('data.0.value', 'not_received');
        $this->post("/api/v1/orders/{$order->id}/problem", [
            'reason' => 'damaged', 'description' => 'Two of the plates arrived broken.',
            'photos' => [UploadedFile::fake()->image('broken.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(OrderStatus::Disputed, $order->fresh()->status);
        $this->assertSame(1, Dispute::sole()->photos()->count());
    }

    public function test_chat_with_wibuy_support_with_text_and_photos(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $id = $this->getJson('/api/v1/chat')->assertOk()->assertJsonPath('support.title', 'WiBuy support')->json('support.id');

        $this->postJson("/api/v1/chat/{$id}/messages", ['body' => 'Hello, where is my order?'])->assertCreated()->assertJsonPath('data.mine', true);
        $this->post("/api/v1/chat/{$id}/messages", ['image' => UploadedFile::fake()->image('receipt.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.type', 'image');

        $messages = $this->getJson("/api/v1/chat/{$id}/messages")->assertOk()->json('data');
        $this->assertCount(2, $messages);
        $this->getJson("/api/v1/chat/{$id}/messages?after_id={$messages[1]['id']}")->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->buyer());
        $this->getJson("/api/v1/chat/{$id}/messages")->assertNotFound();
    }

    public function test_buyer_seller_chat_is_off_unless_switched_on(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($buyer = $this->buyer());

        $this->postJson("/api/v1/chat/direct/{$seller->user_id}")->assertNotFound();

        config(['chat.direct_enabled' => true]);
        $id = $this->postJson("/api/v1/chat/direct/{$seller->user_id}")->assertOk()->json('id');
        $this->postJson("/api/v1/chat/{$id}/messages", ['body' => 'Is the rice still available?'])->assertCreated();

        $this->assertSame(1, app(ChatService::class)->unreadFor($seller->user->fresh()));
    }

    public function test_sms_updates_also_appear_in_the_app_but_login_codes_never_do(): void
    {
        $order = $this->paidOrder();
        $buyer = $order->buyer;
        app(OtpService::class)->send($buyer->phone, '127.0.0.1');

        $this->orders()->accept($order);

        Sanctum::actingAs($buyer);
        $list = $this->getJson('/api/v1/notifications')->assertOk()->json();
        $this->assertGreaterThanOrEqual(2, $list['unread']);
        $this->assertNotEmpty(collect($list['data'])->firstWhere('link', "/orders/{$order->id}"));
        $this->assertFalse(collect($list['data'])->contains(fn ($n) => str_contains($n['body'], 'code')), 'no login codes in the app');

        $this->getJson('/api/v1/notifications/counts')->assertJsonStructure(['notifications', 'chat']);
        $this->postJson('/api/v1/notifications/read')->assertNoContent();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 0);
    }

    public function test_phones_register_for_push_and_pushes_go_out_when_firebase_is_set_up(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/devices', ['token' => 'fcm-token-1', 'platform' => 'android'])->assertNoContent();
        $this->assertSame($user->id, DeviceToken::sole()->user_id);

        // Not set up: no push job.
        Queue::fake();
        app(NotificationService::class)->notify($user, 'Hello', 'Test');
        Queue::assertNothingPushed();

        // Set up: a push job goes out, and the FCM call uses the service account token.
        $path = storage_path('framework/testing-fcm.json');
        file_put_contents($path, json_encode(['client_email' => 'push@wibuy.iam.gserviceaccount.com', 'private_key' => 'unused']));
        Cache::put('fcm-access-token', 'ya29.test', 60); // skip signing a real service account key
        config(['services.fcm.project_id' => 'wibuy-test', 'services.fcm.credentials' => $path]);

        $notification = app(NotificationService::class)->notify($user, 'Your order', 'Order accepted');
        Queue::assertPushed(SendPushJob::class);

        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404),
        ]);
        (new SendPushJob($notification->id))->handle(app(FcmClient::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'projects/wibuy-test/messages:send') && $r->hasHeader('Authorization', 'Bearer ya29.test') && $r['message']['notification']['title'] === 'Your order');
        $this->assertSame(0, DeviceToken::count(), 'an uninstalled app token is removed');
        @unlink($path);
    }

    public function test_free_trial_testers_post_their_review_with_photos_from_the_app(): void
    {
        $product = $this->makeProduct(null, ['price' => '50.00']);
        $trial = TrialCampaign::create(['product_id' => $product->id, 'seller_id' => $product->seller_id, 'trial_price' => '0', 'slots' => 1, 'apply_until' => now()->addWeek()]);
        $tester = $this->buyer();
        TrialApplication::create(['trial_campaign_id' => $trial->id, 'user_id' => $tester->id, 'status' => 'selected', 'selected_at' => now()]);
        $order = $this->paidOrder($product, $tester);
        $this->orders()->accept($order);
        $this->orders()->ship($order, 'x');
        $this->orders()->confirmDelivery($order->fresh(), $tester);
        Sanctum::actingAs($tester);

        $this->post("/api/v1/trials/{$trial->id}/review", [
            'rating' => 4, 'comment' => 'Good taste and quality, packaging was ok.', 'photos' => [UploadedFile::fake()->image('a.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('reviewed', TrialApplication::sole()->status);
        $this->assertTrue(Review::sole()->is_free_trial);
        $this->assertSame(1, AppNotification::where('user_id', $tester->id)->count() > 0 ? 1 : 0);
        $this->assertNotNull(User::find($tester->id));
    }
}
