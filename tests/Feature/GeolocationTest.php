<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SavedAddress;
use App\Models\Seller;
use App\Models\SellerLocation;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Saved addresses with an optional map pin, delivery by radius or district, nearby shops and home privacy. */
class GeolocationTest extends TestCase
{
    use Marketplace;

    /** Central Freetown. */
    private const LAT = 8.4844;

    private const LNG = -13.2299;

    private function shopAt(Seller $seller, array $attributes = []): SellerLocation
    {
        return SellerLocation::create($attributes + [
            'seller_id' => $seller->id, 'type' => 'shop', 'public_name' => $seller->shop_name, 'public_address' => '5 Siaka Stevens Street',
            'district' => 'Western Area Urban', 'latitude' => self::LAT, 'longitude' => self::LNG,
            'delivery_enabled' => true, 'delivery_radius_km' => 5, 'base_fee' => '10.00', 'per_km_fee' => '2.00', 'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function address(array $overrides = []): array
    {
        return $overrides + [
            'label' => 'Home', 'recipient_name' => 'Aminata', 'recipient_phone' => '076 111 222', 'district' => 'Western Area Urban',
            'landmark' => 'Behind the mosque', 'latitude' => self::LAT + 0.02, 'longitude' => self::LNG,
        ];
    }

    public function test_addresses_are_validated_and_private_to_their_owner(): void
    {
        Sanctum::actingAs($owner = $this->buyer());

        $id = $this->postJson('/api/v1/addresses', $this->address())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/addresses', $this->address(['latitude' => 51.5, 'longitude' => -0.12]))
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->postJson('/api/v1/addresses', $this->address(['latitude' => null, 'longitude' => null, 'landmark' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('landmark');
        $this->postJson('/api/v1/addresses', $this->address(['latitude' => null, 'longitude' => null]))->assertCreated();

        Sanctum::actingAs($this->buyer());
        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/addresses/{$id}", $this->address())->assertNotFound();
        $this->deleteJson("/api/v1/addresses/{$id}")->assertNotFound();
        $this->assertSame(2, SavedAddress::where('user_id', $owner->id)->count());
    }

    public function test_delivery_is_priced_by_distance_inside_the_radius_and_by_district_outside_it(): void
    {
        Sanctum::actingAs($this->buyer());
        $seller = $this->makeSeller(['Western Area Urban' => '20.00']);
        $this->shopAt($seller);

        // 0.02 degrees north is 2.2 km: Le 10 + 2.2 x Le 2.
        $this->getJson("/api/v1/geo/delivery/{$seller->slug}?lat=".(self::LAT + 0.02).'&lng='.self::LNG)->assertOk()
            ->assertJsonPath('can_deliver', true)
            ->assertJsonPath('fee', '14.40')
            ->assertJsonPath('distance_km', 2.2);

        $this->getJson("/api/v1/geo/delivery/{$seller->slug}?district=Western%20Area%20Urban")->assertOk()
            ->assertJsonPath('fee', '20.00');

        $this->getJson("/api/v1/geo/delivery/{$seller->slug}?lat=7.96&lng=-11.74&district=Bo")->assertOk()
            ->assertJsonPath('can_deliver', false)
            ->assertJsonPath('reason', "{$seller->shop_name} does not deliver to Bo.");
    }

    public function test_nearby_shops_are_sorted_by_distance_and_a_home_is_never_shown_exactly(): void
    {
        // No login needed: browsing shops near a point is public.
        $far = $this->makeSeller();
        $this->shopAt($far, ['latitude' => self::LAT + 0.05]);
        $home = $this->makeSeller();
        $this->shopAt($home, ['latitude' => 8.488765, 'longitude' => -13.231234, 'is_residence' => true, 'public_address' => '3 Private Lane']);
        $hidden = Seller::factory()->pending()->create();
        $this->shopAt($hidden);

        $this->getJson('/api/v1/geo/nearby?lat='.self::LAT.'&lng='.self::LNG.'&radius_km=10')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.seller.slug', $home->slug)
            ->assertJsonPath('data.0.location.address', null)
            ->assertJsonPath('data.0.location.latitude', 8.49)
            ->assertJsonPath('data.1.seller.slug', $far->slug);

        $this->getJson('/api/v1/geo/nearby?lat=51.5&lng=-0.12')->assertUnprocessable();
    }

    public function test_a_seller_pins_their_shop_on_the_website_but_cannot_touch_another_shops_location(): void
    {
        $seller = $this->makeSeller();
        $theirs = $this->shopAt($this->makeSeller());
        $form = ['type' => 'shop', 'public_name' => 'Main shop', 'district' => 'Western Area Urban', 'latitude' => self::LAT, 'longitude' => self::LNG, 'delivery_enabled' => '1', 'is_active' => '1'];

        $this->actingAs($seller->user)->get(route('seller.locations'))->assertOk()->assertSee('Use my current location');
        $this->actingAs($seller->user)->get(route('seller.group-buys.index'))->assertOk();
        $this->actingAs($seller->user)->post(route('seller.locations.store'), $form)->assertRedirect(route('seller.locations'));
        $this->actingAs($seller->user)->post(route('seller.locations.store'), ['latitude' => 51.5, 'longitude' => -0.12] + $form)->assertSessionHasErrors('latitude');
        $this->actingAs($seller->user)->post(route('seller.locations.update', $theirs), $form)->assertNotFound();

        $this->assertSame(1, $seller->locations()->count());
        $this->assertSame($theirs->seller->shop_name, $theirs->fresh()->public_name);
    }

    public function test_checkout_with_a_saved_address_uses_its_pin_for_the_fee_and_keeps_it_on_the_order(): void
    {
        Sanctum::actingAs($this->buyer());
        $seller = $this->makeSeller(['Western Area Urban' => '20.00']);
        $this->shopAt($seller);
        $product = $this->makeProduct($seller, ['price' => '150.00', 'stock' => 5]);
        $addressId = $this->postJson('/api/v1/addresses', $this->address())->json('data.id');
        $this->postJson("/api/v1/cart/{$product->slug}", ['quantity' => 1]);

        $this->postJson('/api/v1/checkout', [
            'source' => 'cart', 'address_id' => $addressId, 'methods' => [$seller->id => 'delivery'],
            'operator' => 'ORANGE', 'payer_phone' => '076 111 222',
        ])->assertCreated()->assertJsonPath('data.total', '164.40');

        $order = Order::sole();
        $this->assertSame($addressId, $order->saved_address_id);
        $this->assertSame('Behind the mosque', $order->landmark);
        $this->assertEquals(2.2, (float) $order->distance_km);
    }
}
