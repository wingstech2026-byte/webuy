<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\SellerStatus;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** A shop owner's national ID card: needed before approval, kept private, seen only by admins who approve shops. */
class OwnerIdentityTest extends TestCase
{
    use Marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function application(array $overrides = []): array
    {
        return $overrides + [
            'shop_name' => 'Aminata Fabrics', 'district' => 'Bo', 'pickup_address' => 'Bo market, stall 4',
            'payout_phone' => '077 123 456', 'payout_operator' => 'AFRIMONEY', 'offers_pickup' => '1',
            'owner_nationality' => 'Sierra Leone', 'owner_id_type' => 'national_id',
            'owner_id_number' => 'sl 1234-5678',
            'owner_id_front' => UploadedFile::fake()->image('front.jpg', 1200, 800),
            'owner_id_back' => UploadedFile::fake()->image('back.jpg', 1200, 800),
        ];
    }

    public function test_applying_needs_the_id_card_which_is_kept_private_and_encrypted(): void
    {
        $user = $this->buyer();
        $this->actingAs($user)->get(route('sell.apply'))->assertSee('Your identity document')->assertSee('Your nationality');

        $this->actingAs($user)->post(route('sell.store'), $this->application(['owner_id_number' => '', 'owner_id_front' => null]))
            ->assertSessionHasErrors(['owner_id_number', 'owner_id_front']);
        $this->actingAs($user)->post(route('sell.store'), $this->application(['owner_id_number' => '12']))->assertSessionHasErrors('owner_id_number');
        $this->actingAs($user)->post(route('sell.store'), $this->application(['owner_id_front' => UploadedFile::fake()->create('id.pdf', 5, 'application/pdf')]))
            ->assertSessionHasErrors('owner_id_front');
        $this->assertSame(0, Seller::count());

        $this->actingAs($user)->post(route('sell.store'), $this->application())->assertRedirect(route('sell.apply'));

        $seller = Seller::sole();
        $this->assertTrue($seller->hasOwnerIdentity());
        $this->assertSame('SL12345678', $seller->owner_id_number); // spaces and dashes removed
        $raw = DB::table('sellers')->where('id', $seller->id)->value('owner_id_number');
        $this->assertStringNotContainsString('SL12345678', $raw, 'stored encrypted');
        $this->assertSame(Seller::idFingerprint('SL12345678'), $seller->owner_id_number_hash);

        // On the private disk only, re-saved as JPEG, never on the public disk.
        Storage::disk('local')->assertExists([$seller->owner_id_front_path, $seller->owner_id_back_path]);
        $this->assertSame([], Storage::disk('public')->allFiles('identity'));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring(Storage::disk('local')->get($seller->owner_id_front_path))[2]);

        // Never in JSON.
        $this->assertArrayNotHasKey('owner_id_number', $seller->toArray());
        $this->assertArrayNotHasKey('owner_id_front_path', $seller->toArray());
    }

    public function test_the_admin_cannot_approve_without_the_id_and_sees_it_when_sent(): void
    {
        Storage::disk('local')->put('identity/x/front.jpg', 'jpeg-bytes');
        $seller = Seller::factory()->pending()->create(['shop_name' => 'Old Shop']); // applied before the ID was needed
        $admin = User::factory()->admin(AdminRole::Catalogue)->create();

        $this->actingAs($admin)->get(route('admin.sellers.show', $seller))->assertSee('has not sent their identity document yet');
        $this->actingAs($admin)->post(route('admin.sellers.approve', $seller))
            ->assertSessionHasErrors(['general' => 'This shop cannot be approved yet: the owner has not sent their identity document (ID card or passport, number and photo).']);
        $this->assertSame(SellerStatus::Pending, $seller->fresh()->status);

        // The owner sends it from the "Sell on WiBuy" page.
        $this->actingAs($seller->user)->get(route('sell.apply'))->assertSee('We need your identity document (ID card or passport) before we can approve your shop.');
        $this->actingAs($seller->user)->post(route('sell.identity'), [
            'owner_nationality' => 'Sierra Leone', 'owner_id_type' => 'passport',
            'owner_id_number' => 'AB998877', 'owner_id_front' => UploadedFile::fake()->image('front.jpg'),
        ])->assertRedirect(route('sell.apply'));

        $seller->refresh();
        $this->actingAs($admin)->get(route('admin.sellers.show', $seller))->assertSee('AB998877')->assertSee(route('admin.sellers.id-card', [$seller, 'front']));
        $this->actingAs($admin)->get(route('admin.sellers.id-card', [$seller, 'front']))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->actingAs($admin)->get(route('admin.sellers.id-card', [$seller, 'back']))->assertNotFound();

        $this->actingAs($admin)->post(route('admin.sellers.approve', $seller))->assertSessionHas('status');
        $this->assertSame(SellerStatus::Approved, $seller->fresh()->status);

        // Once approved, the card cannot be swapped by the owner.
        $this->actingAs($seller->user)->post(route('sell.identity'), ['owner_nationality' => 'Sierra Leone', 'owner_id_type' => 'passport', 'owner_id_number' => 'ZZ111111', 'owner_id_front' => UploadedFile::fake()->image('x.jpg')])
            ->assertSessionHasErrors('general');
        $this->assertSame('AB998877', $seller->fresh()->owner_id_number);
    }

    public function test_only_admins_who_approve_shops_see_the_card(): void
    {
        $seller = Seller::factory()->pending()->withOwnerIdentity()->create();
        Storage::disk('local')->put($seller->owner_id_front_path, 'jpeg-bytes');
        $url = route('admin.sellers.id-card', [$seller, 'front']);

        $this->actingAs($this->buyer())->get($url)->assertForbidden();
        $this->actingAs($seller->user)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->admin(AdminRole::Support)->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->admin(AdminRole::Owner)->create())->get($url)->assertOk();
    }

    public function test_the_same_id_on_two_shops_is_flagged(): void
    {
        $first = Seller::factory()->withOwnerIdentity('SL555555')->create(['shop_name' => 'First Shop']);
        $second = Seller::factory()->pending()->withOwnerIdentity('SL555555')->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.sellers.show', $second))->assertSee('The same ID number was sent for:')->assertSee('First Shop');
        $this->actingAs($admin)->get(route('admin.sellers.show', $first))->assertSee($second->shop_name);
    }

    public function test_sierra_leoneans_send_an_id_card_or_passport_and_others_any_official_id(): void
    {
        // A Sierra Leonean cannot send a residence permit.
        $local = $this->buyer();
        $this->actingAs($local)->post(route('sell.store'), $this->application(['owner_id_type' => 'residence_permit']))
            ->assertSessionHasErrors(['owner_id_type' => 'Sierra Leoneans send their national ID card or passport.']);
        $this->actingAs($local)->post(route('sell.store'), $this->application(['owner_nationality' => 'Atlantis']))->assertSessionHasErrors('owner_nationality');
        $this->assertSame(0, Seller::count());

        // A Nigerian sends the passport photo page; a Lebanese trader a residence permit.
        $this->actingAs($this->buyer())->post(route('sell.store'), $this->application(['owner_nationality' => 'Nigeria', 'owner_id_type' => 'passport', 'owner_id_number' => 'A12345678']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->buyer())->post(route('sell.store'), $this->application(['shop_name' => 'Cedar Traders', 'owner_nationality' => 'Lebanon', 'owner_id_type' => 'residence_permit', 'owner_id_number' => 'RP-445566']))
            ->assertSessionHasNoErrors();

        $nigerian = Seller::where('owner_nationality', 'Nigeria')->sole();
        $this->assertSame(['passport', 'A12345678'], [$nigerian->owner_id_type, $nigerian->owner_id_number]);
        $this->assertSame('residence_permit', Seller::where('owner_nationality', 'Lebanon')->sole()->owner_id_type);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.sellers.show', $nigerian))
            ->assertSee('Nationality: <strong>Nigeria</strong>', false)->assertSee('Document: <strong>Passport</strong>', false);
    }
}
