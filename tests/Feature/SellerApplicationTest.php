<?php

namespace Tests\Feature;

use App\Enums\SellerStatus;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class SellerApplicationTest extends TestCase
{
    use Marketplace;

    private function application(array $overrides = []): array
    {
        return array_merge([
            'shop_name' => 'Aminata Fabrics',
            'description' => 'Ankara and lace',
            'district' => 'Bo',
            'area' => 'Bo town',
            'pickup_address' => 'Bo market, stall 4',
            'payout_phone' => '077 123 456',
            'payout_operator' => 'AFRIMONEY',
            'business_registration_no' => 'BRS-12345',
            'offers_delivery' => '1',
            'offers_pickup' => '1',
        ], $overrides);
    }

    public function test_the_apply_page_needs_login_and_shows_the_form(): void
    {
        $this->get(route('sell.apply'))->assertRedirect(route('login'));

        $this->actingAs($this->buyer())->get(route('sell.apply'))
            ->assertOk()->assertSee('Send my application')->assertSee('Mobile money number for payouts');
    }

    public function test_a_buyer_can_apply_with_a_logo(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $user = $this->buyer();

        $this->actingAs($user)->post(route('sell.store'), $this->application([
            'logo' => UploadedFile::fake()->image('logo.png', 800, 800),
        ]))->assertRedirect(route('sell.apply'));

        $seller = $user->fresh()->seller;
        $this->assertSame(SellerStatus::Pending, $seller->status);
        $this->assertSame('Aminata Fabrics', $seller->shop_name);
        $this->assertSame('aminata-fabrics', $seller->slug);
        $this->assertSame('23277123456', $seller->payout_phone); // normalised
        $this->assertFalse($seller->is_verified);
        $this->assertFalse($user->fresh()->isSeller(), 'not a seller until approved');

        $logo = $seller->logo;
        $this->assertSame('ready', $logo->status);
        [$w] = getimagesizefromstring(Storage::disk('public')->get($logo->path));
        $this->assertSame(512, $w); // logos are resized to 512px
        Storage::disk('public')->assertExists($logo->thumb_path);
    }

    public function test_the_application_is_validated(): void
    {
        $user = $this->buyer();

        $this->actingAs($user)->post(route('sell.store'), [])
            ->assertSessionHasErrors(['shop_name', 'district', 'pickup_address', 'payout_phone', 'payout_operator']);

        $this->actingAs($user)->post(route('sell.store'), $this->application(['payout_phone' => '123']))->assertSessionHasErrors('payout_phone');
        $this->actingAs($user)->post(route('sell.store'), $this->application(['district' => 'Lagos']))->assertSessionHasErrors('district');
        $this->actingAs($user)->post(route('sell.store'), $this->application(['offers_delivery' => '0', 'offers_pickup' => '0']))->assertSessionHasErrors('offers_pickup');
        $this->actingAs($user)->post(route('sell.store'), $this->application([
            'logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
        ]))->assertSessionHasErrors('logo');

        $this->assertSame(0, Seller::count());
    }

    public function test_you_cannot_apply_twice(): void
    {
        $user = $this->buyer();
        $this->actingAs($user)->post(route('sell.store'), $this->application());
        $this->actingAs($user)->post(route('sell.store'), $this->application(['shop_name' => 'Second shop']))
            ->assertSessionHasErrors('general');

        $this->assertSame(1, Seller::count());
    }

    public function test_pending_sellers_cannot_use_the_seller_dashboard(): void
    {
        $seller = Seller::factory()->pending()->create();

        $this->actingAs($seller->user)->get(route('seller.dashboard'))->assertRedirect(route('sell.apply'));
        $this->actingAs($seller->user)->get(route('seller.products.create'))->assertRedirect(route('sell.apply'));
        $this->actingAs($this->buyer())->get(route('seller.dashboard'))->assertRedirect(route('sell.apply'));
    }

    public function test_admin_approves_with_a_verified_badge_and_the_seller_is_texted(): void
    {
        $seller = Seller::factory()->pending()->create(['shop_name' => 'Bo Traders']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.sellers.index'))->assertOk()->assertSee('Bo Traders');

        $this->actingAs($admin)->post(route('admin.sellers.approve', $seller), ['verified' => '1'])->assertRedirect();

        $seller->refresh();
        $this->assertSame(SellerStatus::Approved, $seller->status);
        $this->assertTrue($seller->is_verified);
        $this->assertNotNull($seller->approved_at);
        $this->assertTrue($seller->user->fresh()->isSeller());
        $this->assertStringContainsString('is approved', $this->sms->lastTo($seller->user->phone));
        $this->assertStringContainsString('Buy and sell easy for Salone.', $this->sms->lastTo($seller->user->phone));

        $this->actingAs($seller->user->fresh())->get(route('seller.dashboard'))->assertOk()->assertSee('Bo Traders');
    }

    public function test_admin_rejects_and_the_seller_can_reapply(): void
    {
        $seller = Seller::factory()->pending()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.sellers.reject', $seller), ['reason' => 'Add a real pickup address'])->assertRedirect();

        $seller->refresh();
        $this->assertSame(SellerStatus::Rejected, $seller->status);
        $this->assertStringContainsString('Add a real pickup address', $this->sms->lastTo($seller->user->phone));

        $this->actingAs($seller->user)->post(route('sell.store'), $this->application(['pickup_address' => 'Real address 1']))->assertRedirect();
        $this->assertSame(SellerStatus::Pending, $seller->fresh()->status);
        $this->assertNull($seller->fresh()->rejection_reason);
    }

    public function test_admin_can_toggle_verified_and_suspend(): void
    {
        $seller = Seller::factory()->create();
        $product = $this->makeProduct($seller);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.sellers.verify', $seller));
        $this->assertTrue($seller->fresh()->is_verified);

        $this->actingAs($admin)->post(route('admin.sellers.suspend', $seller));
        $this->assertSame(SellerStatus::Suspended, $seller->fresh()->status);

        // A suspended shop and its products disappear for buyers.
        $this->actingAs($this->buyer());
        $this->get(route('shops.show', $seller))->assertNotFound();
        $this->get(route('products.show', $product))->assertNotFound();
        $this->get(route('search'))->assertDontSee($product->title);
    }

    public function test_only_admins_manage_sellers(): void
    {
        $seller = Seller::factory()->pending()->create();

        $this->actingAs($this->buyer())->post(route('admin.sellers.approve', $seller))->assertForbidden();
        $this->assertSame(SellerStatus::Pending, $seller->fresh()->status);
    }

    public function test_sellers_set_delivery_fees_per_district(): void
    {
        $seller = $this->makeSeller([]);

        $this->actingAs($seller->user)->put(route('seller.shop.delivery'), ['fees' => [
            'Western Area Urban' => '25', 'Bo' => '60.50', 'Kenema' => '',
        ]])->assertRedirect(route('seller.shop'));

        $this->assertSame(['Bo' => '60.50', 'Western Area Urban' => '25.00'], $seller->deliveryAreas()->pluck('fee', 'district')->all());
        $this->assertSame('60.50', $seller->fresh()->deliveryFeeFor('Bo'));
        $this->assertNull($seller->fresh()->deliveryFeeFor('Kenema'));
    }
}
