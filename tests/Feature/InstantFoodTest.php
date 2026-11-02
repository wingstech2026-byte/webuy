<?php

namespace Tests\Feature;

use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\AppNotification;
use App\Models\Checkout;
use App\Models\DeliveryJob;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerLocation;
use App\Models\TeamOrder;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InstantService;
use App\Services\ShopStaffService;
use App\Services\TeamOrderService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Instant food around offices: nearby shops, paying before cooking, "Food ready" calls riders, scheduled lunch, office team orders. */
class InstantFoodTest extends TestCase
{
    use Marketplace;

    private const OFFICE = ['lat' => 8.4840, 'lng' => -13.2299];

    private function foodShop(array $attributes = [], float $lat = 8.4930): Seller
    {
        $seller = $this->makeSeller(['Western Area Urban' => '20.00'], ['is_instant' => true, 'prep_minutes' => 20] + $attributes);
        SellerLocation::create([
            'seller_id' => $seller->id, 'type' => 'shop', 'public_name' => $seller->shop_name, 'district' => 'Western Area Urban',
            'latitude' => $lat, 'longitude' => -13.2299, 'is_active' => true, 'delivery_enabled' => true, 'delivery_radius_km' => 5, 'base_fee' => '15.00',
        ]);

        return $seller;
    }

    private function order(User $buyer, Product $product, array $details = []): Checkout
    {
        return app(CheckoutService::class)->place(
            $buyer,
            [['product_id' => $product->id, 'quantity' => 1]],
            $details + ['name' => $buyer->name, 'phone' => $buyer->phone, 'district' => 'Western Area Urban', 'address' => 'Africell House', 'latitude' => self::OFFICE['lat'], 'longitude' => self::OFFICE['lng']],
            [$product->seller_id => 'delivery'],
            Operator::Orange,
            $buyer->phone,
        );
    }

    private function availableRider(float $lat): User
    {
        $rider = User::factory()->create(['is_rider' => true]);
        app(InstantService::class)->setAvailable($rider, $lat, -13.2299);

        return $rider;
    }

    public function test_buyers_see_food_shops_that_deliver_to_them_with_a_time(): void
    {
        $near = $this->foodShop(['shop_name' => 'Mama Fatu Kitchen']);
        $this->makeProduct($near, ['title' => 'Jollof rice']);
        $this->makeProduct($this->foodShop(['shop_name' => 'Far Away Grill'], 8.60), ['title' => 'Grilled fish']); // 13 km away

        $this->getJson('/api/v1/instant?lat=8.4840&lng=-13.2299')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.seller.shop_name', 'Mama Fatu Kitchen')
            ->assertJsonPath('data.0.eta_minutes', 29) // 20 cooking + 5 + 1 km × 4
            ->assertJsonPath('data.0.products.0.title', 'Jollof rice');

        $this->get(route('instant.index', ['lat' => 8.4840, 'lng' => -13.2299]))->assertOk()->assertSee('Mama Fatu Kitchen')->assertSee('about 29 min')->assertDontSee('Far Away Grill');
    }

    public function test_instant_food_needs_the_exact_spot_and_an_open_shop_or_a_time_when_it_is_open(): void
    {
        $this->travelTo(now()->setTime(21, 0));
        $product = $this->makeProduct($this->foodShop(['opens_at' => '08:00', 'closes_at' => '20:00']));
        $buyer = $this->buyer();

        foreach ([
            [['latitude' => null, 'longitude' => null], 'share your location'],
            [[], 'is closed now (open 08:00–20:00)'],
            [['deliver_at' => now()->addMinutes(20)->toIso8601String()], 'Choose a delivery time from'],
            [['deliver_at' => now()->addHours(2)->toIso8601String()], 'is closed at that time'],
        ] as [$details, $error]) {
            try {
                $this->order($buyer, $product, $details);
                $this->fail("Expected: {$error}");
            } catch (MarketplaceException $e) {
                $this->assertStringContainsString($error, $e->getMessage());
            }
        }

        // Tomorrow's lunch at 13:00 is fine, and the shop has until it must start cooking to accept.
        $lunch = now()->addDay()->setTime(13, 0);
        $order = $this->pay($this->order($buyer, $product, ['deliver_at' => $lunch->toIso8601String()]))->orders()->sole();
        $this->assertTrue($order->is_instant);
        $this->assertTrue($order->deliver_at->eq($lunch));
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $order->accept_by->timestamp, 2);
    }

    public function test_paid_food_must_be_accepted_in_10_minutes_or_the_buyer_is_refunded(): void
    {
        $order = $this->pay($this->order($this->buyer(), $this->makeProduct($this->foodShop())))->orders()->sole();

        $this->assertEqualsWithDelta(now()->addMinutes(10)->timestamp, $order->accept_by->timestamp, 2);
        $this->assertSame('⚡ Instant food order', AppNotification::where('user_id', $order->seller->user_id)->latest('id')->value('title'));

        $this->travel(11)->minutes();
        $this->artisan('wibuy:refund-unaccepted');
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status); // cancelled, and the money already went back
    }

    public function test_food_ready_calls_the_nearest_available_riders_and_food_jobs_come_first(): void
    {
        $order = $this->pay($this->order($this->buyer(), $this->makeProduct($this->foodShop())))->orders()->sole();
        $near = $this->availableRider(8.4950);
        $far = $this->availableRider(8.70); // about 23 km away
        $off = User::factory()->create(['is_rider' => true]);
        $this->orders()->accept($order);

        $this->actingAs($order->seller->user)->post(route('seller.orders.food-ready', $order))->assertSessionHas('status');

        $job = DeliveryJob::sole();
        $this->assertTrue($job->is_instant);
        $this->assertNotNull($order->fresh()->food_ready_at);
        $this->assertSame(['⚡ Food delivery near you'], AppNotification::where('user_id', $near->id)->pluck('title')->all());
        $this->assertSame(0, AppNotification::whereIn('user_id', [$far->id, $off->id])->count());
        $this->assertSame('🍲 Your food is ready', AppNotification::where('user_id', $order->buyer_id)->latest('id')->value('title'));
        $this->actingAs($order->seller->user)->post(route('seller.orders.food-ready', $order))->assertSessionHasErrors('general');

        // Riders see the food job first, even when it is older.
        $other = $this->paidOrder($this->makeProduct(), null, 1, 'delivery');
        $this->orders()->accept($other);
        $this->actingAs($other->seller->user)->post(route('seller.orders.rider', $other));
        Sanctum::actingAs($near);
        $this->getJson('/api/v1/rider/jobs')->assertJsonPath('open.0.is_instant', true)->assertJsonPath('open.1.is_instant', false);
    }

    public function test_scheduled_lunch_reminds_the_shop_to_cook_in_time(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $order = $this->pay($this->order($this->buyer(), $this->makeProduct($this->foodShop()), ['deliver_at' => now()->setTime(13, 0)->toIso8601String()]))->orders()->sole();
        $this->orders()->accept($order);

        $this->travelTo(now()->setTime(12, 20));
        $this->artisan('wibuy:instant-tick');
        $this->assertSame(0, AppNotification::where('title', '⏰ Start cooking')->count());

        $this->travelTo(now()->setTime(12, 26)); // 13:00 minus 20 min cooking minus 15
        $this->artisan('wibuy:instant-tick');
        $this->artisan('wibuy:instant-tick');
        $this->assertSame(1, AppNotification::where('title', '⏰ Start cooking')->count());
        $this->assertStringContainsString('must arrive at 13:00', $this->sms->lastTo($order->seller->user->phone));
    }

    public function test_an_office_order_puts_colleagues_meals_in_one_trip_and_the_organiser_pays_delivery_once(): void
    {
        $shop = $this->foodShop();
        $rice = $this->makeProduct($shop, ['title' => 'Jollof rice']);
        [$organiser, $colleague] = [$this->buyer(), $this->buyer()];

        Sanctum::actingAs($organiser);
        $token = $this->postJson('/api/v1/team', [
            'shop' => $shop->slug, 'title' => 'Friday lunch', 'closes_in' => 30, 'name' => 'Aminata', 'phone' => '076 555 111',
            'district' => 'Western Area Urban', 'address' => 'Siaka Stevens Street', 'building' => 'Africell House', 'floor' => '3',
            'latitude' => self::OFFICE['lat'], 'longitude' => self::OFFICE['lng'],
        ])->assertCreated()->assertJsonPath('data.place', 'Africell House, floor 3, Siaka Stevens Street')->json('data.token');
        $team = TeamOrder::where('token', $token)->sole();

        $mine = $this->pay($this->order($organiser, $rice, ['team_order_id' => $team->id]))->orders()->sole();
        $theirs = $this->pay($this->order($colleague, $rice, ['team_order_id' => $team->id, 'address' => 'ignored']))->orders()->sole();

        $this->assertSame(['15.00', '0.00'], [$mine->delivery_fee, $theirs->delivery_fee]);
        $this->assertSame('Africell House, floor 3, Siaka Stevens Street', $theirs->address);
        $this->get(route('team.show', $team))->assertOk()->assertSee('Friday lunch')->assertSee('Already in:');

        // The shop marks one ready: both are called, and one rider takes both in one trip.
        $this->orders()->accept($mine);
        $this->orders()->accept($theirs);
        $this->actingAs($shop->user)->post(route('seller.orders.food-ready', $theirs))->assertSessionHas('status');
        $this->assertSame(2, DeliveryJob::count());

        $rider = User::factory()->create(['is_rider' => true]);
        $this->actingAs($rider)->post(route('rider.jobs.act', [DeliveryJob::first(), 'accept-route']))->assertSessionHas('status');
        $this->assertSame(2, DeliveryJob::where('rider_id', $rider->id)->count());

        // Closed: nobody else can join.
        $this->travel(31)->minutes();
        $this->artisan('wibuy:instant-tick');
        $this->expectExceptionMessage('This office order is closed.');
        $this->order($this->buyer(), $rice, ['team_order_id' => $team->id]);
    }

    public function test_a_rider_is_available_for_three_hours_then_their_spot_is_forgotten(): void
    {
        $rider = User::factory()->create(['is_rider' => true]);

        $this->actingAs($rider)->post(route('rider.available'), ['lat' => 8.49, 'lng' => -13.23])->assertSessionHas('status');
        $this->assertTrue($rider->fresh()->isRiderAvailable());
        $this->actingAs($this->buyer())->post(route('rider.available'), ['lat' => 8.49, 'lng' => -13.23])->assertSessionHasErrors('general');

        $this->travel(3)->hours();
        $this->travel(1)->minutes();
        $this->artisan('wibuy:instant-tick');
        $this->assertNull($rider->fresh()->rider_available_lat);
    }

    public function test_a_shop_turns_instant_delivery_on_only_once_it_is_on_the_map_and_a_helper_can_call_a_rider(): void
    {
        $seller = $this->makeSeller();
        $this->actingAs($seller->user)->put(route('seller.shop.instant'), ['is_instant' => 1, 'opens_at' => '08:00', 'closes_at' => '20:00', 'prep_minutes' => 25])
            ->assertSessionHasErrors('is_instant');

        $shop = $this->foodShop();
        $order = $this->pay($this->order($this->buyer(), $this->makeProduct($shop)))->orders()->sole();
        $this->orders()->accept($order);
        $helper = $this->buyer(['phone' => '23277888999']);
        app(ShopStaffService::class)->add($shop, '077 888 999');

        $this->actingAs($helper->fresh())->post(route('seller.orders.food-ready', $order))->assertSessionHas('status');
        $this->assertSame(1, DeliveryJob::count());
    }

    public function test_an_office_address_has_a_building_and_floor(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->postJson('/api/v1/addresses', [
            'label' => 'My office', 'recipient_name' => 'Aminata', 'recipient_phone' => '076 555 111', 'district' => 'Western Area Urban',
            'street' => 'Siaka Stevens Street', 'building' => 'Africell House', 'floor' => '3',
        ])->assertCreated()->assertJsonPath('data.building', 'Africell House')->assertJsonPath('data.floor', '3');
    }

    public function test_only_food_shops_can_have_office_orders(): void
    {
        $this->expectExceptionMessage('Office orders are for food shops');

        app(TeamOrderService::class)->start($this->buyer(), $this->makeSeller(), ['name' => 'A', 'phone' => '23276555111', 'district' => 'Western Area Urban', 'address' => 'x', 'latitude' => 8.48, 'longitude' => -13.23], 'Lunch', 30, null);
    }
}
