<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\LedgerType;
use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\LedgerService;
use App\Services\StationService;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** WiBuy pickup stations: the shop drops the parcel at its station; a rider carries it on; the buyer collects with a code. */
class PickupStationTest extends TestCase
{
    use Marketplace;

    private Station $shopStation;

    private Station $buyerStation;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // The shop has no map point: its station is the one in its district (Western Area Urban).
        $this->shopStation = Station::factory()->create(['name' => 'Kissy Mini Mart', 'district' => 'Western Area Urban']);
        $this->buyerStation = Station::factory()->create(['name' => 'Bo Phone Stand', 'district' => 'Bo', 'latitude' => 7.96, 'longitude' => -11.74]);
        $this->product = $this->makeProduct(null, ['price' => '500.00', 'stock' => 10]);
    }

    private function order(string $method, ?Station $collect = null, ?User $buyer = null): Order
    {
        $buyer ??= $this->buyer();
        $checkout = app(CheckoutService::class)->place($buyer, [['product_id' => $this->product->id, 'quantity' => 1]],
            ['name' => $buyer->name, 'phone' => $buyer->phone, 'district' => 'Western Area Urban', 'address' => '12 Wilkinson Road', 'station_id' => $collect?->id],
            [$this->product->seller_id => $method], Operator::Orange, $buyer->phone);
        $order = $this->pay($checkout)->orders()->sole();
        $this->orders()->accept($order);

        return $order->fresh();
    }

    private function code(Order $order): string
    {
        preg_match('/(?:collect it|goods): (\d{4})/', $this->sms->lastTo($order->buyer->phone), $m);

        return $m[1];
    }

    public function test_collect_at_another_station_a_rider_carries_it_and_everyone_is_paid(): void
    {
        $order = $this->order('station', $this->buyerStation);
        $this->assertSame('25.00', $order->delivery_fee); // station fee 10 + carrying to another station 15
        $this->assertSame([$this->shopStation->id, $this->buyerStation->id], [$order->drop_station_id, $order->collect_station_id]);
        $seller = $order->seller->user;

        // The seller only drops it; they cannot mark it sent themselves.
        $this->actingAs($seller)->get(route('seller.orders.show', $order))->assertSee('Take this order to the WiBuy station')->assertSee('Kissy Mini Mart');
        $this->actingAs($seller)->post(route('seller.orders.ship', $order))->assertSessionHasErrors('general');

        // 1. The shop's station receives it: a station-to-station job opens.
        $this->actingAs($this->shopStation->partner)->post(route('station.act', 'from-shop'), ['number' => strtolower($order->number)])->assertSessionHas('status');
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $job = DeliveryJob::sole();
        $this->assertSame([DeliveryJob::KIND_TRANSFER, '15.00'], [$job->kind, $job->fee]);

        // 2. A rider carries it; there is no buyer code for a transfer.
        $rider = User::factory()->create(['is_rider' => true]);
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'accept']));
        $this->assertStringContainsString('will collect order', $this->sms->lastTo($this->shopStation->partner->phone));
        $this->actingAs($rider)->get(route('rider.jobs.show', $job))->assertSee('Deliver to WiBuy station')->assertDontSee($order->recipient_phone);
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'picked-up']));
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'deliver']), ['code' => '1234'])->assertSessionHasErrors('general');

        // 3. The buyer's station receives it; the buyer gets a code.
        $this->actingAs($this->buyerStation->partner)->post(route('station.act', 'from-rider'), ['number' => $order->number])->assertSessionHas('status');
        $this->assertSame(DeliveryJob::DELIVERED, $job->fresh()->status);
        $code = $this->code($order);
        $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertSee('It is there now');

        // 4. The buyer collects with the code (a wrong one is counted).
        $this->actingAs($this->buyerStation->partner)->post(route('station.act', 'hand-over'), ['number' => $order->number, 'code' => $code === '1111' ? '2222' : '1111'])
            ->assertSessionHasErrors('general');
        $this->actingAs($this->buyerStation->partner)->post(route('station.act', 'hand-over'), ['number' => $order->number, 'code' => $code])->assertSessionHas('status');
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        // 5. Completed: the seller gets the goods minus commission, the partners their share, the rider the carry fee.
        $this->orders()->complete($order->fresh());
        $ledger = app(LedgerService::class);
        $this->assertSame('475.00', $ledger->available($order->seller)); // 500 - 5% commission; no station fees
        $this->assertSame('4.00', $ledger->riderAvailable($this->shopStation->partner));
        $this->assertSame('4.00', $ledger->riderAvailable($this->buyerStation->partner));
        $this->assertSame('15.00', $ledger->riderAvailable($rider));
        app(StationService::class)->creditOnCompletion($order->fresh()); // nothing paid twice
        $this->assertSame(2, LedgerEntry::where('type', LedgerType::StationFee)->count());
    }

    public function test_collect_at_the_shops_own_station_is_ready_at_once(): void
    {
        $order = $this->order('station', $this->shopStation);
        $this->assertSame('10.00', $order->delivery_fee);

        $this->actingAs($this->shopStation->partner)->post(route('station.act', 'from-shop'), ['number' => $order->number]);

        $this->assertSame(0, DeliveryJob::count());
        $this->assertStringContainsString('is ready at Kissy Mini Mart', $this->sms->lastTo($order->buyer->phone));
        $this->actingAs($this->shopStation->partner)->get(route('station.index'))->assertSee('Waiting for the buyer (1)')->assertSee($order->number);
    }

    public function test_delivery_from_the_station_to_the_door(): void
    {
        $order = $this->order('station_door');
        $this->assertSame('30.00', $order->delivery_fee); // station fee 10 + rider 20
        $this->assertNull($order->collect_station_id);

        $this->actingAs($this->shopStation->partner)->post(route('station.act', 'from-shop'), ['number' => $order->number]);
        $job = DeliveryJob::sole();
        $rider = User::factory()->create(['is_rider' => true]);
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'accept']));
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'picked-up']))->assertSessionHasNoErrors();
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'deliver']), ['code' => $this->code($order)])->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->orders()->complete($order->fresh());
        $this->assertSame('20.00', app(LedgerService::class)->riderAvailable($rider));
        $this->assertSame('4.00', app(LedgerService::class)->riderAvailable($this->shopStation->partner));
    }

    public function test_checkout_needs_a_station_and_shops_far_from_stations_cannot_use_them(): void
    {
        $buyer = $this->buyer();
        $this->actingAs($buyer)->get(route('checkout.show', ['product' => $this->product->slug]))
            ->assertSee('Collect at a WiBuy station')->assertSee('WiBuy brings it to my address')->assertSee('Bo Phone Stand');

        $this->expectExceptionMessage('Choose the WiBuy station where you will collect your order.');
        try {
            $this->order('station', null, $buyer);
        } finally {
            $far = $this->makeProduct($this->makeSeller(['Bo' => '10.00'], ['district' => 'Kono']));
            $this->actingAs($buyer)->get(route('checkout.show', ['product' => $far->slug]))->assertDontSee('Collect at a WiBuy station');
        }
    }

    public function test_admin_adds_a_station_and_only_partners_open_the_station_page(): void
    {
        $partner = $this->buyer(['name' => 'Fatu Kamara']);
        $admin = User::factory()->admin(AdminRole::Support)->create();

        $this->actingAs($admin)->post(route('admin.stations.store'), [
            'phone' => $partner->display_phone, 'name' => "Fatu's Mini Mart", 'address' => 'Lumley Road, by the mosque',
            'district' => 'Western Area Urban', 'latitude' => '8.42', 'longitude' => '-13.27', 'opening_hours' => 'Mon–Sat 8am–8pm',
        ])->assertSessionHas('status');
        $this->actingAs($admin)->post(route('admin.stations.store'), ['phone' => '076 000 111', 'name' => 'X', 'address' => 'Y', 'district' => 'Bo', 'latitude' => '51.5', 'longitude' => '-0.1'])
            ->assertSessionHasErrors('latitude'); // London is not in Sierra Leone
        $this->actingAs($admin)->post(route('admin.stations.store'), ['phone' => '076 000 111', 'name' => 'X', 'address' => 'Y', 'district' => 'Bo', 'latitude' => '7.9', 'longitude' => '-11.7'])
            ->assertSessionHasErrors('phone'); // nobody has that number

        $this->actingAs($partner)->get(route('station.index'))->assertOk()->assertSee("Fatu's Mini Mart");
        $this->actingAs($partner)->get(route('rider.earnings'))->assertOk();
        $this->actingAs($this->buyer())->get(route('station.index'))->assertForbidden();
    }
}
