<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\DeliveryJob;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** WiBuy riders: request, take, collect, hand over with the buyer's code (or a photo). */
class DeliveryRiderTest extends TestCase
{
    use Marketplace;

    private Order $order;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $buyer = $this->buyer(['name' => 'Aminata Kamara']);
        $this->order = $this->paidOrder(null, $buyer, 1, 'delivery');
        $this->orders()->accept($this->order);
        $this->rider = User::factory()->create(['name' => 'Ibrahim Rider', 'is_rider' => true]);
    }

    private function seller(): User
    {
        return $this->order->seller->user;
    }

    private function job(): DeliveryJob
    {
        return DeliveryJob::sole();
    }

    private function requestAndTake(): void
    {
        $this->actingAs($this->seller())->post(route('seller.orders.rider', $this->order))->assertSessionHasNoErrors();
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'accept']))->assertRedirect();
    }

    private function buyerCode(): string
    {
        preg_match('/code only when you have your goods: (\d{4})/', $this->sms->lastTo($this->order->buyer->phone), $m);

        return $m[1];
    }

    public function test_a_rider_takes_collects_and_hands_over_with_the_buyers_code(): void
    {
        $this->actingAs($this->seller())->post(route('seller.orders.rider', $this->order))->assertSessionHasNoErrors();
        $this->assertSame($this->order->delivery_fee, $this->job()->fee);

        // Before taking it, the rider sees the area and the fee, not the buyer.
        $this->actingAs($this->rider)->get(route('rider.index'))->assertOk()->assertSee('Take this job')->assertDontSee('Aminata');
        $this->actingAs($this->rider)->get(route('rider.jobs.show', $this->job()))->assertOk()->assertDontSee($this->order->recipient_phone);

        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'accept']))->assertRedirect();
        $this->assertStringContainsString('Ibrahim Rider', $this->sms->lastTo($this->seller()->phone));
        $this->actingAs($this->rider)->get(route('rider.jobs.show', $this->job()))->assertSee($this->order->recipient_phone);

        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'picked-up']))->assertRedirect();
        $this->assertSame(OrderStatus::Shipped, $this->order->fresh()->status);
        $code = $this->buyerCode();

        $wrong = $code === '1234' ? '4321' : '1234';
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'deliver']), ['code' => $wrong])->assertSessionHasErrors('general');
        $this->assertSame(1, $this->job()->fresh()->code_attempts);

        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'deliver']), ['code' => $code])->assertSessionHasNoErrors();
        $this->assertSame(DeliveryJob::DELIVERED, $this->job()->fresh()->status);
        $this->assertSame('code', $this->job()->fresh()->proof);
        $this->assertSame(OrderStatus::Delivered, $this->order->fresh()->status);
    }

    public function test_the_buyer_follows_the_riders_steps_and_sees_the_phone_only_while_on_the_way(): void
    {
        $buyer = $this->order->buyer;
        $riderPhone = $this->rider->display_phone;

        $this->actingAs($this->seller())->post(route('seller.orders.rider', $this->order));
        $this->actingAs($buyer)->get(route('orders.show', $this->order))->assertSee('Looking for a WiBuy rider');

        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'accept']));
        $this->actingAs($buyer)->get(route('orders.show', $this->order))
            ->assertSee('The rider is going to the shop to collect your order')->assertSee('Ibrahim Rider')->assertSee($riderPhone);

        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'picked-up']));
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/orders/{$this->order->id}")->assertOk()
            ->assertJsonPath('data.rider_delivery.stage', 'picked_up')
            ->assertJsonPath('data.rider_delivery.rider_phone', $this->rider->phone)
            ->assertJsonPath('data.rider_delivery.steps.1.label', 'Rider collected your order')
            ->assertJsonPath('data.rider_delivery.steps.2.at', null);

        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'deliver']), ['code' => $this->buyerCode()]);
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/orders/{$this->order->id}")->assertJsonPath('data.rider_delivery.label', 'Delivered by the WiBuy rider')
            ->assertJsonPath('data.rider_delivery.rider_phone', null);
        $this->actingAs($buyer)->get(route('orders.show', $this->order))->assertSee('Delivered by the WiBuy rider')->assertDontSee('tel:+'.$this->rider->phone, false);
    }

    public function test_after_five_wrong_codes_the_code_stops_working(): void
    {
        $this->requestAndTake();
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'picked-up']));
        $code = $this->buyerCode();
        $wrong = $code === '1234' ? '4321' : '1234';

        foreach (range(1, 5) as $try) {
            $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'deliver']), ['code' => $wrong]);
        }
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'deliver']), ['code' => $code])
            ->assertSessionHasErrors(['general' => 'Too many wrong codes. Take a photo of the handover instead.']);

        $this->assertSame(DeliveryJob::PICKED_UP, $this->job()->fresh()->status);
    }

    public function test_a_photo_is_the_proof_when_the_buyer_cannot_give_the_code(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $this->requestAndTake();
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'picked-up']));

        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job(), 'deliver']), [
            'photo' => UploadedFile::fake()->image('handover.jpg'), 'note' => 'Given to her brother Musa',
        ])->assertRedirect();

        $this->assertSame('photo', $this->job()->fresh()->proof);
        $this->assertSame(1, $this->job()->photos()->count());
        $this->assertSame(OrderStatus::Delivered, $this->order->fresh()->status);
    }

    public function test_only_one_rider_gets_a_job_and_others_cannot_see_its_details(): void
    {
        $this->requestAndTake();
        $other = User::factory()->create(['is_rider' => true]);

        $this->actingAs($other)->post(route('rider.jobs.act', [$this->job(), 'accept']))->assertSessionHasErrors('general');
        $this->actingAs($other)->get(route('rider.jobs.show', $this->job()))->assertNotFound();
        $this->actingAs($other)->post(route('rider.jobs.act', [$this->job(), 'picked-up']))->assertSessionHasErrors('general');
        $this->actingAs($this->buyer())->get(route('rider.index'))->assertForbidden();

        $this->assertSame($this->rider->id, $this->job()->fresh()->rider_id);
    }

    public function test_the_seller_can_cancel_before_collection_and_pickup_orders_cannot_get_a_rider(): void
    {
        $this->requestAndTake();
        $this->actingAs($this->seller())->post(route('seller.deliveries.cancel', $this->job()))->assertSessionHasNoErrors();
        $this->assertSame(DeliveryJob::CANCELLED, $this->job()->fresh()->status);
        $this->assertStringContainsString('cancelled the delivery', $this->sms->lastTo($this->rider->phone));

        $pickup = $this->paidOrder();
        $this->orders()->accept($pickup);
        $this->actingAs($pickup->seller->user)->post(route('seller.orders.rider', $pickup))->assertSessionHasErrors('general');
    }

    public function test_riders_work_from_the_app_and_admins_choose_who_rides(): void
    {
        $this->actingAs($this->seller())->post(route('seller.orders.rider', $this->order));
        Sanctum::actingAs($this->rider);

        $this->getJson('/api/v1/rider/jobs')->assertOk()->assertJsonPath('open.0.buyer_phone', null);
        $this->postJson("/api/v1/rider/jobs/{$this->job()->id}/accept")->assertOk()->assertJsonPath('data.buyer_phone', $this->order->recipient_phone);

        $admin = User::factory()->admin()->create();
        $newRider = User::factory()->create(['phone' => '23277555666']);
        $this->actingAs($admin)->post(route('admin.riders.toggle'), ['phone' => '077 555 666'])->assertRedirect();
        $this->assertTrue($newRider->fresh()->is_rider);
        $this->actingAs($admin)->post(route('admin.riders.toggle'), ['phone' => $this->rider->phone])->assertSessionHasErrors('phone');
        $this->actingAs($admin)->get(route('admin.riders.index'))->assertOk()->assertSee('Ibrahim Rider');
    }
}
