<?php

namespace Tests\Feature\Business;

use App\Enums\MessageType;
use App\Models\AssistantLog;
use App\Models\Category;
use App\Models\ImportOrder;
use App\Models\ImportProduct;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatService;
use App\Services\ReliabilityService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Reliability score, risk warnings, the AI shopping assistant and WiBuy Global imports. */
class TrustAndExtrasTest extends TestCase
{
    use Marketplace;

    public function test_the_reliability_score_counts_on_time_shipping_cancellations_and_problems(): void
    {
        $seller = $this->makeSeller();
        $this->assertNull(app(ReliabilityService::class)->refresh($seller), 'new shops have no score');

        $onTime = $this->paidOrder($this->makeProduct($seller));
        $this->orders()->accept($onTime);
        $this->orders()->ship($onTime, 'x');

        $late = $this->paidOrder($this->makeProduct($seller));
        $this->orders()->accept($late);
        $this->travel(5)->days();
        $this->orders()->ship($late->fresh(), 'x');

        $this->orders()->reject($this->paidOrder($this->makeProduct($seller)), 'No stock');

        // 1 of 2 shipped on time (0.6 x 0.5), 1 of 3 rejected (0.25 x 2/3), no disputes (0.15): 61.7.
        $this->assertSame(62, app(ReliabilityService::class)->refresh($seller));
        $this->get(route('shops.show', $seller))->assertSee('Delivery reliability: 62/100');
        $this->getJson("/api/v1/shops/{$seller->slug}")->assertJsonPath('data.reliability_score', 62);
    }

    public function test_search_ranks_reliable_sellers_first(): void
    {
        $weak = $this->makeSeller(attributes: ['reliability_score' => 40]);
        $strong = $this->makeSeller(attributes: ['reliability_score' => 95]);
        $this->makeProduct($weak, ['title' => 'Cement bag A']);
        $this->makeProduct($strong, ['title' => 'Cement bag B']);

        $this->getJson('/api/v1/products?q=cement')->assertJsonPath('data.0.title', 'Cement bag B');
    }

    public function test_buyers_are_warned_about_very_low_prices_and_new_sellers(): void
    {
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones']);
        foreach (['1000.00', '1100.00', '900.00', '1050.00'] as $price) {
            $this->makeProduct(null, ['category_id' => $category->id, 'price' => $price]);
        }
        $suspicious = $this->makeProduct(null, ['category_id' => $category->id, 'price' => '200.00']);

        $warnings = $this->getJson("/api/v1/products/{$suspicious->slug}")->json('data.risk_warnings');
        $this->assertSame(['low_price', 'new_seller'], array_column($warnings, 'type'));
        $this->get(route('products.show', $suspicious))->assertSee('much lower than similar products');
    }

    public function test_asking_to_be_paid_outside_wibuy_in_chat_adds_a_safety_warning(): void
    {
        $chat = app(ChatService::class);
        $buyer = $this->buyer();
        $seller = $this->makeSeller()->user;
        $conversation = $chat->directBetween($buyer, $seller);

        $chat->post($seller, $conversation, 'Hello, the rice is in stock.');
        $this->assertSame(0, Message::where('type', MessageType::System)->count());

        $chat->post($seller, $conversation, 'Please send the money directly to my Orange Money, it is cheaper.');
        $this->assertStringContainsString('never pay outside WiBuy', Message::where('type', MessageType::System)->sole()->body);
    }

    private function fakeClaude(array $understood): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => json_encode($understood)]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
        ])]);
    }

    public function test_the_ai_assistant_is_off_unless_switched_on(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->postJson('/api/v1/assistant', ['message' => 'cement'])->assertNotFound();
    }

    public function test_the_ai_assistant_understands_a_request_builds_a_shortlist_and_drafts_an_rfq(): void
    {
        config(['wibuy.features.assistant' => true, 'wibuy.assistant.api_key' => 'test-key']);
        $bo = $this->makeSeller(['Bo' => '30.00']);
        $this->makeProduct($bo, ['title' => 'Leocem cement 50kg', 'price' => '480.00', 'stock' => 100]);
        $this->makeProduct(null, ['title' => 'Cement premium', 'price' => '900.00']);
        $this->fakeClaude(['product' => 'cement', 'category' => null, 'quantity' => 50, 'unit' => 'bags', 'district' => 'Bo',
            'max_unit_price' => null, 'max_total' => 30000, 'needed_by' => today()->addWeek()->toDateString(), 'reply' => 'I will look for 50 bags of cement for Bo.']);
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $answer = $this->postJson('/api/v1/assistant', ['message' => 'I need 50 bags of cement delivered to Bo next week under Le 30,000'])
            ->assertOk()->assertJsonPath('reply', 'I will look for 50 bags of cement for Bo.')->json();

        $this->assertSame(['Leocem cement 50kg'], array_column($answer['shortlist'], 'title'), 'Le 900 is over Le 600 a bag');
        $this->assertSame(['quantity' => 50, 'district' => 'Bo', 'target_price' => '600.00'], array_intersect_key($answer['rfq_draft'], array_flip(['quantity', 'district', 'target_price'])));

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'test-key') && $request->hasHeader('anthropic-version')
            && $request['model'] === 'claude-opus-5-5' && $request['output_config']['format']['type'] === 'json_schema' && $request['fallbacks'] === 'default');
        $this->assertSame(160, AssistantLog::sole()->input_tokens + AssistantLog::sole()->output_tokens);

        $this->postJson('/api/v1/assistant/rfq', $answer['rfq_draft'])->assertCreated()->assertJsonPath('data.title', '50 bags cement');
    }

    public function test_the_ai_assistant_has_a_daily_limit_and_fails_politely(): void
    {
        config(['wibuy.features.assistant' => true, 'wibuy.assistant.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/assistant', ['message' => 'rice please'])->assertUnprocessable()
            ->assertJsonPath('message', 'The assistant is not available right now. Please search or post a request for quotes yourself.');
        $this->assertSame('error', AssistantLog::sole()->status);

        foreach (range(1, 19) as $i) {
            AssistantLog::create(['user_id' => $user->id, 'message' => 'x']);
        }
        $this->postJson('/api/v1/assistant', ['message' => 'rice please'])->assertUnprocessable()
            ->assertJsonPath('message', 'You used the assistant 20 times today. Try again tomorrow, or search yourself.');
    }

    public function test_wibuy_global_deposit_tracking_and_balance_on_arrival(): void
    {
        config(['wibuy.features.global' => true]);
        $product = ImportProduct::create(['title' => 'Solar panels 300W', 'min_quantity' => 10, 'sea_price' => '700.00', 'sea_days' => 45, 'air_price' => '950.00', 'air_days' => 10, 'deposit_percent' => 30]);
        $buyer = $this->buyer();
        Sanctum::actingAs($buyer);

        $this->getJson('/api/v1/global/products')->assertJsonPath('data.0.options.sea.price', '700.00');
        $this->postJson('/api/v1/global/orders', ['import_product_id' => $product->id, 'quantity' => 5, 'shipping' => 'sea', 'operator' => 'ORANGE', 'payer_phone' => '076111222'])
            ->assertUnprocessable();
        $order = $this->postJson('/api/v1/global/orders', ['import_product_id' => $product->id, 'quantity' => 10, 'shipping' => 'sea', 'operator' => 'ORANGE', 'payer_phone' => '076111222'])
            ->assertCreated()->assertJsonPath('data.deposit_amount', '2100.00')->assertJsonPath('data.balance_amount', '4900.00')->json('data');

        $this->postJson("/api/v1/payments/{$order['payment_id']}/simulate", ['outcome' => 'approve'])->assertOk();
        $import = ImportOrder::findOrFail($order['id']);
        $this->assertSame('ordered', $import->status);

        $this->postJson("/api/v1/global/orders/{$import->id}/balance", ['operator' => 'ORANGE', 'payer_phone' => '076111222'])->assertUnprocessable();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.global.orders.advance', $import), ['status' => 'completed'])->assertSessionHasErrors('general');
        $this->post(route('admin.global.orders.advance', $import), ['status' => 'at_port'])->assertSessionHas('status');
        $this->assertStringContainsString('At Freetown port', $this->sms->lastTo($buyer->phone));

        Sanctum::actingAs($buyer);
        $paymentId = $this->postJson("/api/v1/global/orders/{$import->id}/balance", ['operator' => 'ORANGE', 'payer_phone' => '076111222'])->assertCreated()->json('payment_id');
        $this->postJson("/api/v1/payments/{$paymentId}/simulate", ['outcome' => 'approve'])->assertOk();
        $this->assertNotNull($import->fresh()->balance_paid_at);

        $this->actingAs($admin)->post(route('admin.global.orders.advance', $import), ['status' => 'completed'])->assertSessionHas('status');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/global/orders')->assertJsonPath('data.0.steps.0.label', 'Ordered');
    }

    public function test_wibuy_global_is_off_unless_switched_on(): void
    {
        config(['wibuy.features.global' => false]);
        Sanctum::actingAs($this->buyer());

        $this->getJson('/api/v1/global/products')->assertNotFound();
    }

    public function test_every_new_admin_page_loads(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        foreach (['admin.business.index', 'admin.inspections.index', 'admin.global.index', 'admin.assistant.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }
}
