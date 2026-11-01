<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\DeliveryJob;
use App\Models\Order;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** The buyer follows the rider live while the parcel is on its way; nothing is kept after delivery. */
class RiderTrackingTest extends TestCase
{
    use Marketplace;

    private Order $order;

    private User $rider;

    private DeliveryJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        // The buyer's address has a map point (Wilkinson Road, Freetown).
        $this->order = $this->paidOrder(null, null, 1, 'delivery');
        $this->order->forceFill(['delivery_latitude' => 8.4700, 'delivery_longitude' => -13.2600])->save();
        $this->orders()->accept($this->order);
        $this->rider = User::factory()->create(['is_rider' => true, 'name' => 'Ibrahim Rider']);

        $this->actingAs($this->order->seller->user)->post(route('seller.orders.rider', $this->order));
        $this->job = DeliveryJob::sole();
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job, 'accept']));
    }

    private function share(float $lat, float $lng)
    {
        return $this->actingAs($this->rider)->postJson(route('rider.jobs.location', $this->job), ['lat' => $lat, 'lng' => $lng]);
    }

    public function test_the_buyer_sees_the_rider_live_and_gets_near_messages_once(): void
    {
        // Not before the rider has the parcel.
        $this->share(8.48, -13.23)->assertStatus(422)->assertJsonPath('message', 'Your location is only shared while you carry the parcel.');

        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job, 'picked-up']));
        $this->actingAs($this->rider)->get(route('rider.jobs.show', $this->job))->assertSee('your location is shared with the buyer');

        // About 5 km away: no message yet.
        $this->share(8.4950, -13.2250)->assertOk();
        $buyer = $this->order->buyer;
        $this->actingAs($buyer)->getJson(route('orders.tracking', $this->order))->assertOk()
            ->assertJsonPath('rider_delivery.tracking.rider.lat', 8.495)
            ->assertJsonPath('rider_delivery.tracking.live', true)
            ->assertJsonPath('rider_delivery.tracking.destination.lat', 8.47);
        $this->assertSame(0, AppNotification::where('user_id', $buyer->id)->where('title', 'like', '🛵%')->count());

        // About 2.4 km, then about 0.6 km: one message each, never repeated.
        $this->share(8.4830, -13.2450);
        $this->share(8.4820, -13.2450);
        $this->share(8.4730, -13.2570);
        $this->share(8.4720, -13.2580);
        $this->assertSame(['🛵 On the way', '🛵 Almost there!'], AppNotification::where('user_id', $buyer->id)->where('title', 'like', '🛵%')->orderBy('id')->pluck('title')->all());
        $this->assertStringContainsString('less than 1 km from you', AppNotification::where('title', '🛵 Almost there!')->sole()->body);

        // The page shows the live map; the app gets the same tracking.
        $this->actingAs($buyer)->get(route('orders.show', $this->order))->assertSee('data-tracking', false)->assertSee('km away');
        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/orders/{$this->order->id}")->assertJsonPath('data.rider_delivery.tracking.km', fn ($km) => $km < 1);
    }

    public function test_only_the_buyer_sees_it_only_the_rider_shares_it_and_it_is_wiped_at_delivery(): void
    {
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job, 'picked-up']));
        $this->share(8.48, -13.25)->assertOk();

        $this->actingAs($this->buyer())->getJson(route('orders.tracking', $this->order))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_rider' => true]))->postJson(route('rider.jobs.location', $this->job), ['lat' => 8.4, 'lng' => -13.2])->assertStatus(422);
        $this->share(51.5, -0.12)->assertStatus(422); // not a real point here

        preg_match('/goods: (\d{4})/', $this->sms->lastTo($this->order->buyer->phone), $m);
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$this->job, 'deliver']), ['code' => $m[1]]);

        $job = $this->job->fresh();
        $this->assertNull($job->rider_latitude);
        $this->assertNull($job->rider_located_at);
        $this->actingAs($this->order->buyer)->getJson(route('orders.tracking', $this->order))->assertJsonPath('rider_delivery.tracking', null);
        $this->share(8.48, -13.25)->assertStatus(422);
    }
}
