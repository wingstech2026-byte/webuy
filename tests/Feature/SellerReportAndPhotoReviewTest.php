<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Image;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Seller sales reports, and buyers adding photos to product reviews. */
class SellerReportAndPhotoReviewTest extends TestCase
{
    use Marketplace;

    public function test_the_report_counts_paid_sales_by_period_week_and_product(): void
    {
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0)); // a Wednesday
        $seller = $this->makeSeller();
        $rice = $this->makeProduct($seller, ['title' => 'Rice 50kg', 'price' => '600.00', 'stock' => 100]);
        $oil = $this->makeProduct($seller, ['title' => 'Palm oil', 'price' => '100.00', 'stock' => 3]);
        $this->makeProduct($seller, ['title' => 'Sugar', 'stock' => 0]);
        $buyer = $this->buyer();

        $this->paidOrder($rice, $buyer, 2);              // 1200, this week
        $this->paidOrder($oil, $buyer, 1);               // 100, same buyer again
        $this->paidOrder($oil, null, 1)->forceFill(['paid_at' => now()->subWeeks(3)])->save(); // 3 weeks ago
        $this->paidOrder($rice, null, 1)->forceFill(['paid_at' => now()->subDays(45)])->save(); // the period before
        $this->paidOrder($rice, null, 1)->forceFill(['status' => OrderStatus::Refunded])->save(); // not a sale
        $this->paidOrder($this->makeProduct(), null, 1); // another shop

        Sanctum::actingAs($seller->user);
        $report = $this->getJson(route('api.v1.seller.reports', ['days' => 30]))->assertOk()->json();

        $this->assertSame(['orders' => 3, 'sales' => '1400.00', 'kept' => '1330.00', 'average_order' => '466.66', 'items_sold' => 4,
            'buyers' => 2, 'repeat_buyers' => 1, 'lost_orders' => 1], $report['totals']);
        $this->assertSame('600.00', $report['previous_sales']);
        $this->assertSame(['Rice 50kg', 'Palm oil'], array_column($report['best_sellers'], 'title'));
        $this->assertSame([2, 2], array_column($report['best_sellers'], 'quantity'));
        $this->assertSame(['Sugar', 'Palm oil'], array_column($report['low_stock'], 'title'));

        $this->assertCount(8, $report['weeks']);
        $this->assertSame(['sales' => '1300.00', 'orders' => 2], array_intersect_key(end($report['weeks']), array_flip(['sales', 'orders'])));
        $this->assertSame('100.00', $report['weeks'][4]['sales']);

        $this->assertSame(1, $this->getJson(route('api.v1.seller.reports', ['days' => 7]))->json('totals.buyers'));

        $this->actingAs($seller->user)->get(route('seller.reports'))->assertOk()
            ->assertSee('Sales report')->assertSee('Le 1,400.00')->assertSee('Rice 50kg')->assertSee('Sold out')->assertSee('▲ 133%');
        $this->actingAs($buyer)->get(route('seller.reports'))->assertRedirect();
    }

    public function test_buyers_add_photos_to_reviews_and_those_reviews_show_first(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $product = $this->makeProduct(null, ['stock' => 10]);

        $first = $this->completedOrder($product);
        $this->actingAs($first->buyer)->post(route('orders.review', $first), [
            'items' => [$first->items()->value('id') => ['rating' => 4, 'comment' => 'No photo here']],
        ])->assertRedirect();

        $second = $this->completedOrder($product);
        $this->actingAs($second->buyer)->get(route('orders.show', $second))->assertSee('Photos of what you got');
        $this->actingAs($second->buyer)->post(route('orders.review', $second), [
            'items' => [$second->items()->value('id') => ['rating' => 5, 'comment' => 'See the bag', 'photos' => [
                UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'),
            ]]],
        ])->assertRedirect();

        $withPhotos = Review::where('comment', 'See the bag')->sole();
        $this->assertSame(2, $withPhotos->images()->count());
        $this->assertSame(['ready', 'ready'], $withPhotos->images()->pluck('status')->all()); // processed (sync queue in tests)

        // The newer review is first anyway; check the order also holds when the photo review is older.
        $this->assertSame('See the bag', $product->reviews()->first()->comment);
        $third = $this->completedOrder($product);
        $this->actingAs($third->buyer)->post(route('orders.review', $third), ['items' => [$third->items()->value('id') => ['rating' => 3, 'comment' => 'Newest, no photo']]]);
        $this->assertSame(['See the bag', 'Newest, no photo', 'No photo here'], $product->reviews()->pluck('comment')->all());

        // From the app (multipart).
        $fourth = $this->completedOrder($product);
        Sanctum::actingAs($fourth->buyer);
        $this->post("/api/v1/orders/{$fourth->id}/review", ['items' => [$fourth->items()->value('id') => ['rating' => 5, 'photos' => [UploadedFile::fake()->image('c.jpg')]]]], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertSame(3, Image::where('collection', 'review')->count());

        $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->assertJsonCount(2, 'reviews.0.photos')->assertJsonPath('reviews.0.comment', 'See the bag');
    }

    public function test_only_photos_and_at_most_four_per_product(): void
    {
        $order = $this->completedOrder($this->makeProduct());
        $item = $order->items()->value('id');

        $this->actingAs($order->buyer)->post(route('orders.review', $order), ['items' => [$item => ['rating' => 5, 'photos' => [UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')]]]])
            ->assertSessionHasErrors("items.{$item}.photos.0");
        $this->actingAs($order->buyer)->post(route('orders.review', $order), ['items' => [$item => ['rating' => 5, 'photos' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 5))]]])
            ->assertSessionHasErrors("items.{$item}.photos");
        $this->assertSame(0, Review::count());
    }

    private function completedOrder($product): Order
    {
        return $this->orders()->complete($this->shippedOrder($product));
    }
}
