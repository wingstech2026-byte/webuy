<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\AppNotification;
use App\Models\User;
use Database\Seeders\ProductionAdminSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class ProductPriceTrackingTest extends TestCase
{
    use Marketplace;

    public function test_price_history_and_one_time_target_alerts(): void
    {
        $shop = $this->makeSeller([], ['rating_count' => 10, 'rating_avg' => 4.5]);
        $product = $this->makeProduct($shop, ['price' => '30.00']);
        $url = "/api/v1/products/{$product->slug}";
        $this->postJson("$url/price-alert", ['target' => '20.00'])->assertUnauthorized();

        $buyer = $this->buyer();
        Sanctum::actingAs($buyer);
        $this->postJson("$url/price-alert", ['target' => '-1'])->assertUnprocessable();
        $this->postJson("$url/price-alert", ['target' => '20.00'])->assertOk();

        $product->update(['price' => '20.00']);
        $this->artisan('wibuy:price-alerts')->assertSuccessful();
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $buyer->id, 'title' => 'Price alert']);

        $product->update(['price' => '19.00']);
        $this->artisan('wibuy:price-alerts')->assertSuccessful();
        $this->artisan('wibuy:price-alerts')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $buyer->id)->where('title', 'Price alert')->count());

        $this->getJson($url)->assertOk()->assertJsonCount(3, 'price_history')
            ->assertJsonPath('price_alert', null)->assertJsonPath('seller_badges.0', 'Top rated');
    }

    public function test_the_website_can_save_and_remove_a_price_alert(): void
    {
        $shop = $this->makeSeller([], ['rating_count' => 10, 'rating_avg' => 4.5]);
        $product = $this->makeProduct($shop);
        $page = route('products.show', $product);
        $alert = route('products.price-alert', $product);

        $this->get($page)->assertOk()->assertSee('Base price history')->assertSee('Top rated');
        $this->get(route('shops.show', $shop))->assertOk()->assertSee('Top rated');
        $this->post($alert, ['target' => '30.00'])->assertRedirect(route('login'));

        $buyer = $this->buyer();
        $this->actingAs($buyer)->from($page)->post($alert, ['target' => '30.00'])->assertRedirect($page);
        $this->get($page)->assertSee('Remove price alert');
        $this->from($page)->post($alert, ['target' => ''])->assertRedirect($page);
        $this->assertDatabaseMissing('product_price_alerts', ['user_id' => $buyer->id, 'product_id' => $product->id]);
    }

    public function test_production_seeding_only_creates_the_configured_owner(): void
    {
        config(['wibuy.admin.phone' => '076 543 210', 'wibuy.admin.name' => 'WiBuy Owner']);

        $this->seed(ProductionAdminSeeder::class);
        $this->seed(ProductionAdminSeeder::class);

        $owner = User::sole();
        $this->assertSame('23276543210', $owner->phone);
        $this->assertTrue($owner->is_admin);
        $this->assertSame(AdminRole::Owner, $owner->admin_role);
        $this->assertDatabaseEmpty('products');
    }
}
