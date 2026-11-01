<?php

namespace Tests\Feature;

use App\Models\AssistantLog;
use App\Support\RewardSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Search by photo: Claude names the item, WiBuy searches its own products with the words. */
class PhotoSearchTest extends TestCase
{
    use Marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wibuy.features.photo_search' => true, 'wibuy.assistant.api_key' => 'test-key']);
    }

    /** Claude answers with each of these in turn, then keeps repeating the last one. */
    private function fakeClaude(array ...$answers): void
    {
        $reply = fn (array $understood) => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($understood)]],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 50],
        ]);
        $sequence = Http::sequence();

        foreach ($answers as $understood) {
            $sequence->pushResponse($reply($understood));
        }

        Http::fake(['api.anthropic.com/*' => $sequence->whenEmpty($reply(end($answers)))]);
    }

    private function chair(): array
    {
        return ['is_item' => true, 'name' => 'White plastic garden chair', 'search_words' => ['plastic chair', 'garden chair', 'white'],
            'category' => null, 'reply' => 'This looks like a white plastic garden chair.'];
    }

    public function test_a_photo_finds_matching_products_best_first(): void
    {
        $this->makeProduct(null, ['title' => 'Plastic chairs (white)', 'price' => '120.00']);
        $this->makeProduct(null, ['title' => 'Office chair with wheels', 'price' => '900.00']);
        $this->makeProduct(null, ['title' => 'Rice 50kg bag', 'description' => 'White rice']);
        $this->makeProduct(null, ['title' => 'Plastic bucket']);
        $this->fakeClaude($this->chair());
        Sanctum::actingAs($this->buyer());

        $response = $this->post('/api/v1/search/photo', ['photo' => UploadedFile::fake()->image('chair.jpg', 3000, 2000)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('understood.name', 'White plastic garden chair')
            ->assertJsonPath('products.0.title', 'Plastic chairs (white)');

        // "White rice" shares only one later word in its description: not a match.
        $this->assertNotContains('Rice 50kg bag', array_column($response->json('products'), 'title'));
        $this->assertContains('Office chair with wheels', array_column($response->json('products'), 'title'));

        // The photo is shrunk before it is sent, and sent as an image block.
        Http::assertSent(function ($request) {
            $image = $request['messages'][0]['content'][0];
            [$width, $height] = getimagesizefromstring(base64_decode($image['source']['data']));

            return $image['type'] === 'image' && $image['source']['media_type'] === 'image/jpeg' && max($width, $height) === 1024 && $height === 683;
        });
        $this->assertSame('Photo search', AssistantLog::sole()->message);
    }

    public function test_the_website_shows_results_and_says_when_nothing_is_on_wibuy(): void
    {
        $this->fakeClaude($this->chair(), ['is_item' => false, 'name' => '', 'search_words' => [], 'category' => null, 'reply' => 'I cannot see an item.']);
        $user = $this->buyer();

        $this->actingAs($user)->get(route('search.photo'))->assertOk()->assertSee('Search with a photo');
        $this->actingAs($user)->post(route('search.photo.store'), ['photo' => UploadedFile::fake()->image('chair.png')])
            ->assertOk()->assertSee('This looks like a white plastic garden chair.')
            ->assertSee('No seller has "White plastic garden chair" on WiBuy right now.', false);

        $this->actingAs($user)->post(route('search.photo.store'), ['photo' => UploadedFile::fake()->image('blank.jpg')])
            ->assertOk()->assertSee('We could not see an item to buy in that photo.');

        $this->actingAs($user)->post(route('search.photo.store'), ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('photo');
    }

    public function test_it_has_a_daily_limit_is_off_by_default_and_needs_an_account(): void
    {
        RewardSettings::put('assistant_daily_limit', 1);
        $this->fakeClaude($this->chair());
        Sanctum::actingAs($this->buyer());
        $photo = fn () => ['photo' => UploadedFile::fake()->image('chair.jpg')];

        $this->post('/api/v1/search/photo', $photo(), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('left_today', 0);
        $this->post('/api/v1/search/photo', $photo(), ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonPath('message', 'You searched by photo 1 times today. Try again tomorrow, or type what you want.');

        config(['wibuy.features.photo_search' => false]);
        $this->post('/api/v1/search/photo', $photo(), ['Accept' => 'application/json'])->assertNotFound();
        $this->get('/')->assertDontSee('Search with a photo');

        config(['wibuy.features.photo_search' => true]);
        auth()->forgetGuards();
        $this->get(route('search.photo'))->assertRedirect(route('login'));
    }

    public function test_when_the_ai_service_fails_the_shopper_is_told_to_type_instead(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);
        Sanctum::actingAs($this->buyer());

        $this->post('/api/v1/search/photo', ['photo' => UploadedFile::fake()->image('chair.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'Search by photo is not available right now. Please type what you are looking for.');
        $this->assertSame('error', AssistantLog::sole()->status);
    }
}
