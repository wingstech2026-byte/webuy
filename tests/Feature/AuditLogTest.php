<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GroupBuyCampaign;
use App\Models\User;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use Marketplace;

    public function test_admin_changes_are_logged_without_their_values_and_only_admins_see_the_log(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->makeProduct(null, ['price' => '100.00']);
        $campaign = GroupBuyCampaign::create([
            'product_id' => $product->id, 'seller_id' => $product->seller_id, 'group_price' => '90.00',
            'min_group_size' => 2, 'max_quantity_each' => 3, 'hours' => 24, 'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.group-buys.toggle', $campaign), ['note' => 'secret-value'])->assertRedirect();
        $this->actingAs($admin)->get(route('admin.audit'))->assertOk()->assertSee('admin.group-buys.toggle');
        $this->actingAs($admin)->get(route('admin.group-buys.index'))->assertOk()->assertSee($product->title);

        $log = AuditLog::sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('groupBuyCampaign:'.$campaign->id, $log->subject);
        $this->assertSame(['note'], $log->details['fields']);
        $this->assertStringNotContainsString('secret-value', json_encode($log->toArray()));
        $this->assertFalse($campaign->fresh()->is_active);

        $this->actingAs($this->buyer())->get(route('admin.audit'))->assertForbidden();
    }
}
