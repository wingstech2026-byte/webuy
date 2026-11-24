<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ProductVariant;
use App\Services\ProductStockAlerts;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class ProductStockAlertsTest extends TestCase
{
    use Marketplace;

    public function test_an_alert_waits_for_a_purchasable_option_and_is_sent_once(): void
    {
        $product = $this->makeProduct($this->makeSeller(), ['stock' => 4]);
        $variant = ProductVariant::factory()->for($product)->create(['stock' => 0, 'is_active' => true]);
        $buyer = $this->buyer();
        $url = "/api/v1/products/{$product->slug}";
        $this->postJson("$url/stock-alert", ['subscribed' => true])->assertUnauthorized();

        Sanctum::actingAs($buyer);
        $this->getJson($url)->assertJsonPath('can_set_stock_alert', true);
        $this->postJson("$url/stock-alert", ['subscribed' => true])->assertOk();
        $this->postJson("$url/stock-alert", ['subscribed' => true])->assertOk();
        $this->assertDatabaseCount('product_stock_alerts', 1);

        $alerts = app(ProductStockAlerts::class);
        $alerts->sendAvailable();
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $buyer->id, 'title' => 'Back in stock']);

        $variant->update(['stock' => 2]);
        $alerts->sendAvailable();
        $alerts->sendAvailable();
        $this->assertSame(1, AppNotification::where('user_id', $buyer->id)->where('title', 'Back in stock')->count());
        $this->assertDatabaseEmpty('product_stock_alerts');
        $this->postJson("$url/stock-alert", ['subscribed' => true])->assertUnprocessable();
    }

    public function test_a_website_buyer_can_cancel_a_stock_alert(): void
    {
        $product = $this->makeProduct($this->makeSeller(), ['stock' => 0]);
        $page = route('products.show', $product);
        $alert = route('products.stock-alert', $product);

        $this->actingAs($this->buyer())->get($page)->assertOk()->assertSee('Notify me when back in stock');
        $this->from($page)->post($alert, ['subscribed' => '1'])->assertRedirect($page);
        $this->get($page)->assertSee('Remove stock alert');
        $this->from($page)->post($alert, ['subscribed' => '0'])->assertRedirect($page);
        $this->assertDatabaseEmpty('product_stock_alerts');
    }
}
