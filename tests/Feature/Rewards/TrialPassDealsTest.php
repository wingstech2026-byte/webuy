<?php

namespace Tests\Feature\Rewards;

use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Models\Farm;
use App\Models\FlashDeal;
use App\Models\FlashDealReminder;
use App\Models\Membership;
use App\Models\Review;
use App\Models\TrialApplication;
use App\Models\TrialCampaign;
use App\Services\CheckoutService;
use App\Services\ReviewService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Free trials, WiBuy Pass and lightning deals. */
class TrialPassDealsTest extends TestCase
{
    use Marketplace;

    private function trial(array $attributes = []): TrialCampaign
    {
        $product = $this->makeProduct(null, ['price' => '80.00']);

        return TrialCampaign::create($attributes + ['product_id' => $product->id, 'seller_id' => $product->seller_id, 'trial_price' => '0.00', 'slots' => 1, 'apply_until' => now()->addWeek()]);
    }

    public function test_apply_get_selected_receive_and_post_a_free_trial_review_with_photos(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $trial = $this->trial();
        $tester = $this->buyer();
        Sanctum::actingAs($tester);

        $this->postJson("/api/v1/trials/{$trial->id}/apply", ['reason' => 'I cook for my family'])->assertCreated();
        $this->postJson("/api/v1/trials/{$trial->id}/apply")->assertUnprocessable();

        $application = TrialApplication::firstOrFail();
        $this->actingAs($trial->seller->user)->post(route('seller.promotions.trials.select', $application))->assertSessionHas('status');
        $application->refresh();
        $this->assertSame('selected', $application->status);
        $this->assertSame('80.00', (string) $application->coupon->amount);

        // The coupon makes it free (pickup): no payment needed.
        $checkout = app(CheckoutService::class)->place($tester, [['product_id' => $trial->product_id, 'quantity' => 1]],
            ['name' => 'T', 'phone' => $tester->phone], [$trial->product->seller_id => 'pickup'], Operator::Orange, $tester->phone, $application->coupon_id, 'app');
        $order = $checkout->orders()->first();
        $this->assertSame(OrderStatus::Paid, $order->status);

        $this->orders()->accept($order);
        $this->orders()->ship($order, 'Ready');
        $this->orders()->confirmDelivery($order->fresh(), $tester);
        $this->artisan('wibuy:daily-rewards')->assertSuccessful();
        $this->assertNotNull($application->fresh()->review_due_at);

        $this->actingAs($tester)->post(route('trials.review.store', $trial), [
            'rating' => 4, 'comment' => 'Good quality, but the bag was a little torn.', 'photos' => [UploadedFile::fake()->image('rice.jpg')],
        ])->assertRedirect();

        $review = Review::firstOrFail();
        $this->assertTrue($review->is_free_trial);
        $this->assertSame('reviewed', $application->fresh()->status);
        $this->getJson("/api/v1/products/{$trial->product->slug}")->assertJsonPath('reviews.0.is_free_trial', true);
        $this->get(route('products.show', $trial->product))->assertSee('Free trial');
    }

    public function test_slots_are_limited_and_missing_reviews_become_overdue_and_block_new_trials(): void
    {
        $trial = $this->trial();
        $first = TrialApplication::create(['trial_campaign_id' => $trial->id, 'user_id' => $this->buyer()->id]);
        $second = TrialApplication::create(['trial_campaign_id' => $trial->id, 'user_id' => $this->buyer()->id]);
        $this->actingAs($trial->seller->user);

        $this->post(route('seller.promotions.trials.select', $first))->assertSessionHas('status');
        $this->post(route('seller.promotions.trials.select', $second))->assertSessionHasErrors('general');

        $first->forceFill(['review_due_at' => now()->subDay()])->save();
        $this->artisan('wibuy:daily-rewards');
        $this->assertSame('overdue', $first->fresh()->status);

        Sanctum::actingAs($first->user);
        $this->postJson('/api/v1/trials/'.$this->trial()->id.'/apply')->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot join new free trials because a trial review was never written.');
    }

    public function test_sellers_cannot_pick_testers_for_other_shops(): void
    {
        $application = TrialApplication::create(['trial_campaign_id' => $this->trial()->id, 'user_id' => $this->buyer()->id]);

        $this->actingAs($this->makeSeller()->user)->post(route('seller.promotions.trials.select', $application))->assertNotFound();
    }

    public function test_buying_wibuy_pass_by_mobile_money_and_getting_reminded_before_it_ends(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/pass')->assertOk()->assertJson(['price' => '25.00', 'days' => 30, 'active' => null]);
        $id = $this->postJson('/api/v1/pass', ['operator' => 'ORANGE', 'payer_phone' => '076 555 444'])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/pass', ['operator' => 'ORANGE', 'payer_phone' => '076 555 444'])->assertUnprocessable();

        $this->postJson("/api/v1/pass/{$id}/simulate", ['outcome' => 'approve'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertTrue($user->fresh()->hasPass());
        $this->assertStringContainsString('WiBuy Pass is active', $this->sms->lastTo($user->phone));
        $this->getJson('/api/v1/spin')->assertJsonPath('spins.left', 2, 'members get an extra spin');

        $this->travel(28)->days();
        $this->artisan('wibuy:daily-rewards');
        $this->assertStringContainsString('never charge you automatically', $this->sms->lastTo($user->phone));

        $this->travel(3)->days();
        $this->assertFalse($user->fresh()->hasPass(), 'ends; nothing is charged');
        $this->assertSame(1, Membership::count());
    }

    public function test_a_declined_pass_payment_does_not_activate(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/pass', ['operator' => 'AFRIMONEY', 'payer_phone' => '077 555 444'])->json('data.id');

        $this->postJson("/api/v1/pass/{$id}/simulate", ['outcome' => 'decline'])->assertJsonPath('data.status', 'failed');
        $this->assertFalse($user->fresh()->hasPass());
    }

    public function test_todays_deals_remind_me_and_automatic_rotation(): void
    {
        $live = FlashDeal::create(['product_id' => $this->makeProduct()->id, 'deal_price' => '50.00', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHours(2)]);
        $later = FlashDeal::create(['product_id' => $this->makeProduct()->id, 'deal_price' => '60.00', 'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(5)]);
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/lightning-deals')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.state', 'live')->assertJsonPath('data.1.state', 'upcoming');
        $this->postJson("/api/v1/lightning-deals/{$later->id}/remind")->assertOk()->assertJson(['reminded' => true]);
        $this->getJson("/api/v1/products/{$live->product->slug}")->assertJsonPath('data.flash_deal.deal_price', '50.00');

        $this->artisan('wibuy:rewards-tick');
        $this->assertNull(FlashDealReminder::first()->notified_at, 'not started yet');

        $this->travel(3)->hours();
        $this->artisan('wibuy:rewards-tick');
        $this->assertNotNull(FlashDealReminder::first()->notified_at);
        $this->assertStringContainsString('Lightning deal now', $this->sms->lastTo($user->phone));

        $this->makeProduct(null, ['is_deal' => true, 'price' => '200.00']);
        $this->artisan('wibuy:rotate-deals')->assertSuccessful();
        $auto = FlashDeal::latest('id')->first();
        $this->assertSame('160.00', (string) $auto->deal_price, '20% off by default');
        $this->assertTrue($auto->starts_at->isTomorrow());
    }

    public function test_reviews_count_as_a_farm_mission(): void
    {
        $buyer = $this->buyer();
        Farm::create(['user_id' => $buyer->id, 'code' => 'revfarm', 'plant' => 'rice', 'water' => 0, 'target' => 300]);
        $order = $this->paidOrder(null, $buyer);
        $this->orders()->accept($order);
        $this->orders()->ship($order, 'x');
        $this->orders()->confirmDelivery($order->fresh(), $order->buyer);

        app(ReviewService::class)->submit($order->fresh(), $order->buyer, 5, 'Great', []);

        $this->assertGreaterThan(30, $order->buyer->farm()->first()->water, 'order + review');
    }
}
