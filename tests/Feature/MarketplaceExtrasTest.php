<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Models\AppNotification;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Station;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Code128;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Wishlist alerts, free delivery from an amount, sponsored products, cancelling, rider ratings, labels, one-trip routes. */
class MarketplaceExtrasTest extends TestCase
{
    use Marketplace;

    private function sellerSaves($product, array $fields): void
    {
        $this->actingAs($product->seller->user)->put(route('seller.products.update', $product), $fields + [
            'title' => $product->title, 'price' => (string) $product->price, 'stock' => $product->stock, 'unit' => 'piece',
            'min_order_quantity' => 1, 'condition' => 'new', 'status' => 'active',
        ])->assertSessionHasNoErrors();
    }

    public function test_people_who_saved_a_product_hear_about_a_price_drop_and_a_restock_once(): void
    {
        $product = $this->makeProduct(null, ['price' => '100.00', 'stock' => 0]);
        $fan = $this->buyer();
        $this->actingAs($fan)->post(route('wishlist.toggle', $product));

        $this->sellerSaves($product, ['price' => '90']);
        $this->sellerSaves($product->fresh(), ['price' => '90']); // no new drop: nothing new
        $this->sellerSaves($product->fresh(), ['price' => '90', 'stock' => 5]);

        $this->assertSame(['💸 Price dropped', '📦 Back in stock'], AppNotification::where('user_id', $fan->id)->orderBy('id')->pluck('title')->all());
        $this->assertStringContainsString('now Le 90.00 (was Le 100.00)', AppNotification::where('title', '💸 Price dropped')->sole()->body);
    }

    public function test_free_delivery_from_an_amount_is_paid_by_the_shop_also_to_a_rider(): void
    {
        $seller = $this->makeSeller(['Western Area Urban' => '20.00'], ['free_delivery_from' => '300.00']);
        $big = $this->makeProduct($seller, ['price' => '400.00', 'stock' => 5]);
        $small = $this->makeProduct($seller, ['price' => '100.00', 'stock' => 5]);

        $this->get(route('products.show', $big))->assertSee('Free delivery from Le 300.00');
        $this->assertSame('20.00', $this->paidOrder($small, null, 1, 'delivery')->delivery_fee);

        $order = $this->paidOrder($big, null, 1, 'delivery');
        $this->assertSame(['0.00', '20.00', '400.00'], [$order->delivery_fee, $order->shop_paid_delivery, $order->total]);

        // The shop asks for a rider: the rider still earns the fee, the shop pays it from the sale.
        $this->orders()->accept($order);
        $this->actingAs($seller->user)->post(route('seller.orders.rider', $order));
        $this->assertSame('20.00', DeliveryJob::sole()->fee);
    }

    public function test_a_seller_sponsors_a_product_from_their_balance_and_it_shows_first_labelled(): void
    {
        $seller = $this->makeSeller();
        $this->makeProduct(null, ['title' => 'Rice A', 'sold_count' => 100]);
        $sponsored = $this->makeProduct($seller, ['title' => 'Rice B']);
        app(LedgerService::class)->adjust($seller, '25.00', 'Opening balance');

        $this->actingAs($seller->user)->post(route('seller.products.promote', $sponsored), ['days' => 7])
            ->assertSessionHasErrors(['general' => 'Sponsoring for 7 day(s) costs Le 70.00. You have Le 25.00 available.']);
        $this->actingAs($seller->user)->post(route('seller.products.promote', $sponsored), ['days' => 1])->assertSessionHas('status');

        $this->assertTrue($sponsored->fresh()->isSponsored());
        $this->assertSame('-10.00', LedgerEntry::where('type', LedgerType::Sponsored)->sole()->amount);
        $this->get(route('search', ['q' => 'Rice']))->assertSeeInOrder(['Rice B', 'Rice A'])->assertSee('Sponsored');
        $this->get('/')->assertSee('📣 Sponsored');
        $this->getJson('/api/v1/home')->assertJsonPath('sponsored.0.title', 'Rice B')->assertJsonPath('sponsored.0.is_sponsored', true);

        $this->actingAs($this->buyer())->post(route('seller.products.promote', $sponsored), ['days' => 1])->assertRedirect();
        $this->assertSame(1, LedgerEntry::where('type', LedgerType::Sponsored)->count());
    }

    public function test_a_buyer_cancels_before_the_shop_accepts_and_gets_the_money_back(): void
    {
        $order = $this->paidOrder();
        $stock = $order->items()->sole()->product->fresh()->stock;

        $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertSee('Cancel my order');
        $this->actingAs($this->buyer())->post(route('orders.cancel', $order))->assertForbidden();
        $this->actingAs($order->buyer)->post(route('orders.cancel', $order))->assertSessionHas('status');

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status); // the fake gateway refunds at once
        $this->assertSame($stock + 1, $order->items()->sole()->product->fresh()->stock);
        $this->assertStringContainsString('cancelled order', $this->sms->lastTo($order->seller->user->phone));

        // After the shop accepts, it is too late.
        $accepted = $this->paidOrder();
        $this->orders()->accept($accepted);
        Sanctum::actingAs($accepted->buyer);
        $this->getJson("/api/v1/orders/{$accepted->id}")->assertJsonPath('data.can_cancel', false);
        $this->postJson("/api/v1/orders/{$accepted->id}/cancel")->assertStatus(422);
    }

    public function test_the_buyer_rates_the_rider_once_and_the_rider_sees_the_average(): void
    {
        $order = $this->paidOrder(null, null, 1, 'delivery');
        $this->orders()->accept($order);
        $rider = User::factory()->create(['is_rider' => true]);
        $this->actingAs($order->seller->user)->post(route('seller.orders.rider', $order));
        $job = DeliveryJob::sole();

        $this->actingAs($order->buyer)->post(route('orders.rate-rider', $order), ['stars' => 5])->assertSessionHasErrors('general'); // not delivered yet

        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'accept']));
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'picked-up']));
        preg_match('/goods: (\d{4})/', $this->sms->lastTo($order->buyer->phone), $m);
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'deliver']), ['code' => $m[1]]);

        $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertSee('Rate the rider');
        $this->actingAs($order->buyer)->post(route('orders.rate-rider', $order), ['stars' => 4, 'comment' => 'Fast and polite'])->assertSessionHas('status');
        $this->actingAs($order->buyer)->post(route('orders.rate-rider', $order), ['stars' => 1])->assertSessionHasErrors('general');

        $this->assertSame([4, 'Fast and polite'], [$job->fresh()->rating, $job->fresh()->rating_comment]);
        $this->actingAs($rider)->get(route('rider.index'))->assertSee('★ 4');
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.riders.index'))->assertSee('★ 4 (1)');
    }

    public function test_the_parcel_label_has_a_correct_barcode(): void
    {
        // Every symbol is 11 modules wide (the stop 13): the table is complete and well formed.
        foreach (Code128::PATTERNS as $value => $pattern) {
            $this->assertSame($value === 106 ? 13 : 11, array_sum(str_split($pattern)), "symbol {$value}");
        }
        $this->assertCount(107, Code128::PATTERNS);
        // "WB-1": start B, W=55, B=34, -=13, 1=17, checksum (104 + 55 + 68 + 39 + 68) % 103 = 25, stop.
        $this->assertSame([104, 55, 34, 13, 17, 25, 106], Code128::values('WB-1'));

        $order = $this->paidOrder();
        $this->actingAs($order->seller->user)->get(route('seller.orders.label', $order))->assertOk()
            ->assertSee($order->number)->assertSee('aria-label="Barcode '.$order->number.'"', false);
        $this->actingAs($this->buyer())->get(route('seller.orders.label', $order))->assertRedirect();
    }

    public function test_a_rider_takes_all_parcels_between_two_stations_in_one_trip(): void
    {
        $from = Station::factory()->create();
        $to = Station::factory()->create(['district' => 'Bo']);
        $other = Station::factory()->create(['district' => 'Kenema']);
        $make = fn (Station $dropoff) => DeliveryJob::create([
            'order_id' => $this->paidOrder()->id, 'seller_id' => 1, 'status' => DeliveryJob::OPEN, 'kind' => DeliveryJob::KIND_TRANSFER,
            'pickup_station_id' => $from->id, 'dropoff_station_id' => $dropoff->id, 'pickup_name' => 'Station', 'fee' => '15.00',
        ]);
        $jobs = collect(range(1, 7))->map(fn () => $make($to));
        $elsewhere = $make($other);
        $rider = User::factory()->create(['is_rider' => true]);

        $this->actingAs($rider)->get(route('rider.jobs.show', $jobs[0]))->assertSee('Take all 7 parcels on this route');
        $this->actingAs($rider)->post(route('rider.jobs.act', [$jobs[0], 'accept-route']))->assertSessionHas('status');

        // All 7 taken, although a rider may carry at most 5 trips at once: one route is one trip.
        $this->assertSame(7, DeliveryJob::where('rider_id', $rider->id)->count());
        $this->assertSame(DeliveryJob::OPEN, $elsewhere->fresh()->status);
    }
}
