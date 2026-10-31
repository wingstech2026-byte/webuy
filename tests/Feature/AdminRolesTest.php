<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\User;
use Tests\TestCase;

/** Least privilege for staff: each admin role opens only its own pages; owners manage roles. */
class AdminRolesTest extends TestCase
{
    public function test_each_role_opens_only_its_own_pages_and_sees_only_those_in_the_menu(): void
    {
        $support = User::factory()->admin(AdminRole::Support)->create();

        $this->actingAs($support)->get(route('admin.disputes.index'))->assertOk()
            ->assertSee('Disputes')->assertDontSee('Payouts');
        $this->actingAs($support)->get(route('admin.payouts.index'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.settings'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.staff.index'))->assertForbidden();

        $finance = User::factory()->admin(AdminRole::Finance)->create();
        $this->actingAs($finance)->get(route('admin.payouts.index'))->assertOk();
        $this->actingAs($finance)->get(route('admin.orders.index'))->assertOk();
        $this->actingAs($finance)->get(route('admin.sellers.index'))->assertForbidden();

        $marketing = User::factory()->admin(AdminRole::Marketing)->create();
        $this->actingAs($marketing)->get(route('admin.rewards.index'))->assertOk();
        $this->actingAs($marketing)->get(route('admin.rewards.settings'))->assertForbidden();

        $owner = User::factory()->admin()->create();
        $this->actingAs($owner)->get(route('admin.settings'))->assertOk();
        $this->actingAs($owner)->get(route('admin.audit'))->assertOk();
    }

    public function test_an_admin_without_a_role_and_a_normal_user_get_nothing(): void
    {
        $noRole = User::factory()->create(['is_admin' => true]);
        $this->actingAs($noRole)->get(route('admin.dashboard'))->assertForbidden();

        $this->actingAs(User::factory()->create())->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_an_owner_gives_changes_and_removes_roles_but_not_their_own(): void
    {
        $owner = User::factory()->admin()->create();
        $aminata = User::factory()->create(['phone' => '23276123456', 'name' => 'Aminata']);

        $this->actingAs($owner)->post(route('admin.staff.store'), ['phone' => '076 123 456', 'role' => 'support'])->assertRedirect();
        $this->assertSame(AdminRole::Support, $aminata->fresh()->admin_role);
        $this->assertTrue($aminata->fresh()->is_admin);

        $this->actingAs($owner)->post(route('admin.staff.store'), ['phone' => '076 123 456', 'role' => 'finance']);
        $this->assertSame(AdminRole::Finance, $aminata->fresh()->admin_role);

        $this->actingAs($owner)->post(route('admin.staff.store'), ['phone' => '077 999 888', 'role' => 'support'])->assertSessionHasErrors('phone');
        $this->actingAs($owner)->post(route('admin.staff.store'), ['phone' => $owner->phone, 'role' => 'support'])->assertSessionHasErrors('phone');
        $this->assertSame(AdminRole::Owner, $owner->fresh()->admin_role);

        $this->actingAs($owner)->post(route('admin.staff.remove', $aminata))->assertRedirect();
        $this->assertFalse($aminata->fresh()->is_admin);
        $this->assertNull($aminata->fresh()->admin_role);
        $this->actingAs($aminata->fresh())->get(route('admin.dashboard'))->assertForbidden();

        $this->assertTrue(AuditLog::where('action', 'admin.staff.store')->where('subject', null)->exists());
        $this->assertSame('user:'.$aminata->id, AuditLog::where('action', 'admin.staff.remove')->value('subject'));
    }

    public function test_a_non_owner_cannot_give_roles_and_the_role_cannot_be_mass_assigned(): void
    {
        $support = User::factory()->admin(AdminRole::Support)->create();
        $target = User::factory()->create(['phone' => '23276123456']);

        $this->actingAs($support)->post(route('admin.staff.store'), ['phone' => '076 123 456', 'role' => 'owner'])->assertForbidden();
        $this->assertFalse($target->fresh()->is_admin);

        $sneaky = User::create(['name' => 'X', 'phone' => '23277111222', 'admin_role' => 'owner']);
        $this->assertNull($sneaky->fresh()->admin_role);
    }
}
