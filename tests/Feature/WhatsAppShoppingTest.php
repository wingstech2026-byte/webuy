<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Shopping by WhatsApp: signed webhook calls, text and photo searches, replies with links. */
class WhatsAppShoppingTest extends TestCase
{
    use Marketplace;

    private const GRAPH = 'https://graph.facebook.com/v21.0';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp' => [
            'enabled' => true, 'token' => 'wa-token', 'phone_number_id' => '555', 'verify_token' => 'my-verify', 'app_secret' => 'shh', 'graph_url' => self::GRAPH,
        ]]);
    }

    /** Meta's webhook call for one message, signed like Meta signs it. */
    private function incoming(array $message, ?string $secret = 'shh'): TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['messages' => [$message + ['id' => 'wamid.'.uniqid()]]]]]]]]);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
        ], $body);
    }

    /** @return list<string> the bodies WiBuy sent back on WhatsApp */
    private function replies(): array
    {
        return Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/555/messages'))
            ->map(fn ($pair) => $pair[0]['text']['body'])->values()->all();
    }

    public function test_meta_verifies_the_webhook_with_the_secret_token(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=my-verify&hub.challenge=12345')->assertOk()->assertSeeText('12345');
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
    }

    public function test_a_text_gets_matching_products_with_prices_and_links(): void
    {
        Http::fake([self::GRAPH.'/*' => Http::response(['messages' => [['id' => 'out']]])]);
        $rice = $this->makeProduct(null, ['title' => 'Local rice 50kg bag', 'price' => '650.00', 'unit' => 'bag']);
        $this->makeProduct(null, ['title' => 'Solar lamp']);

        $this->incoming(['from' => '23276111222', 'type' => 'text', 'text' => ['body' => 'rice']])->assertOk();
        $this->incoming(['from' => '23276111222', 'type' => 'text', 'text' => ['body' => 'Hello']]);
        $this->incoming(['from' => '23276111222', 'type' => 'text', 'text' => ['body' => 'fridge']]);

        [$found, $hello, $none] = $this->replies();
        $this->assertStringContainsString('1. Local rice 50kg bag - Le 650.00 per bag', $found);
        $this->assertStringContainsString(route('products.show', $rice), $found);
        $this->assertStringNotContainsString('Solar lamp', $found);
        $this->assertStringStartsWith('Welcome to WiBuy!', $hello);
        $this->assertStringContainsString('nobody sells "fridge"', $none);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer wa-token') && ($request['to'] ?? null) === '23276111222');
    }

    public function test_unsigned_or_repeated_calls_send_nothing(): void
    {
        Http::fake([self::GRAPH.'/*' => Http::response([])]);
        $this->makeProduct(null, ['title' => 'Rice']);

        $this->incoming(['from' => '23276111222', 'type' => 'text', 'text' => ['body' => 'rice']], 'not-the-secret')->assertForbidden();

        $message = ['id' => 'wamid.same', 'from' => '23276111222', 'type' => 'text', 'text' => ['body' => 'rice']];
        $this->incoming($message)->assertOk();
        $this->incoming($message)->assertOk(); // Meta resends: answered once

        $this->assertCount(1, $this->replies());

        config(['services.whatsapp.enabled' => false]);
        $this->incoming(['from' => '23276111222', 'type' => 'text', 'text' => ['body' => 'rice']])->assertNotFound();
    }

    public function test_a_photo_is_searched_for_shoppers_with_an_account(): void
    {
        config(['wibuy.features.photo_search' => true, 'wibuy.assistant.api_key' => 'test-key']);
        $photo = UploadedFile::fake()->image('chair.jpg', 400, 300);
        Http::fake([
            self::GRAPH.'/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/media-1', 'file_size' => 2000]),
            'lookaside.fbsbx.com/*' => Http::response(file_get_contents($photo->getRealPath())),
            self::GRAPH.'/*' => Http::response(['messages' => [['id' => 'out']]]),
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                'content' => [['type' => 'text', 'text' => json_encode(['is_item' => true, 'name' => 'Plastic chair', 'search_words' => ['plastic chair'], 'category' => null, 'reply' => 'This looks like a plastic chair.'])]],
            ]),
        ]);
        $this->makeProduct(null, ['title' => 'Plastic chairs (white)', 'price' => '120.00']);
        $this->buyer(['phone' => '23276333444']);

        $this->incoming(['from' => '23276999000', 'type' => 'image', 'image' => ['id' => 'media-1']]); // no account
        $this->incoming(['from' => '23276333444', 'type' => 'image', 'image' => ['id' => 'media-1']]);

        [$noAccount, $found] = $this->replies();
        $this->assertStringStartsWith('To search with a photo, first make a free WiBuy account', $noAccount);
        $this->assertStringStartsWith('This looks like a plastic chair.', $found);
        $this->assertStringContainsString('1. Plastic chairs (white) - Le 120.00', $found);
    }
}
