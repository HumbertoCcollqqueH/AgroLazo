<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('rolePermissions')]
    public function test_role_permissions_follow_the_operational_boundaries(string $role, array $allowed): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->withRole($role)->create();

        foreach (['manage-users', 'view-operations', 'view-buyer-area', 'approve-buyers', 'confirm-payments', 'place-orders'] as $ability) {
            $this->assertSame(in_array($ability, $allowed, true), $user->can($ability), $role.' / '.$ability);
        }

        $user->forceFill(['active' => false])->save();
        foreach ($allowed as $ability) {
            $this->assertFalse($user->can($ability));
        }
    }

    public static function rolePermissions(): array
    {
        return [
            'administrator' => ['admin', ['manage-users', 'view-operations', 'view-buyer-area', 'approve-buyers', 'confirm-payments']],
            'coordinator' => ['coordinator', ['view-operations']],
            'pending buyer' => ['buyer', ['view-buyer-area']],
        ];
    }

    public function test_buyers_can_access_only_their_own_profile_and_purchase_only_after_approval(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $owner = User::factory()->withRole('buyer')->create();
        $other = User::factory()->withRole('buyer')->create();
        $buyer = Buyer::factory()->for($owner)->create();

        $this->assertTrue($owner->can('view', $buyer));
        $this->assertTrue($owner->can('update', $buyer));
        $this->assertFalse($other->can('view', $buyer));
        $this->assertFalse($other->can('update', $buyer));
        $this->assertFalse($owner->can('purchase', $buyer));

        $buyer->forceFill(['status' => 'approved'])->save();
        $this->assertTrue($owner->can('purchase', $buyer));
        $this->assertTrue($owner->can('place-orders'));
        $this->assertFalse($other->can('purchase', $buyer));

        $buyer->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse($owner->can('purchase', $buyer));
        $this->assertFalse($owner->can('place-orders'));
    }

    public function test_guest_administration_requests_require_login_and_make_no_changes(): void
    {
        $target = User::factory()->create();
        $buyer = Buyer::factory()->for($target)->create();

        $this->get(route('admin.users.index'))->assertRedirectToRoute('login');
        $this->patch(route('admin.users.update', $target), $this->accessData())->assertRedirectToRoute('login');
        $this->post(route('admin.buyers.approve', $buyer), ['reason' => 'Revisión de prueba'])->assertRedirectToRoute('login');

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('pending', $buyer->fresh()->status);
    }

    #[DataProvider('unprivilegedRoles')]
    public function test_non_admin_users_cannot_manage_accounts_or_approve_buyers(string $role): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->withRole($role)->create();
        $target = User::factory()->withRole('buyer')->create();
        $buyer = Buyer::factory()->for($target)->create();

        $this->actingAs($actor)->get(route('admin.users.index'))->assertForbidden();
        $this->patch(route('admin.users.update', $target), $this->accessData())->assertForbidden();
        $this->post(route('admin.buyers.approve', $buyer), ['reason' => 'Aprobación no autorizada'])->assertForbidden();

        $this->assertTrue($target->fresh()->active);
        $this->assertTrue($target->hasRole('buyer'));
        $this->assertSame('pending', $buyer->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function unprivilegedRoles(): array
    {
        return ['coordinator' => ['coordinator'], 'buyer' => ['buyer']];
    }

    public function test_admin_can_list_users_and_change_another_account_with_audited_reason(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        $target = User::factory()->withRole('buyer')->create();

        $this->actingAs($admin)->get(route('admin.users.index'))->assertSee($target->email);
        $this->from(route('admin.users.index'))->patch(route('admin.users.update', $target), [
            ...$this->accessData(), 'name' => 'Injected name', 'password' => 'InjectedPassword123',
        ])->assertRedirectToRoute('admin.users.index')->assertSessionHas('status');

        $this->assertFalse($target->fresh()->active);
        $this->assertTrue($target->hasRole('coordinator'));
        $this->assertNotSame('Injected name', $target->fresh()->name);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id, 'action' => 'user.access_updated',
            'subject_id' => $target->id, 'reason' => 'Cambio de equipo de prueba',
        ]);
    }

    public function test_admin_cannot_demote_or_disable_their_own_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();

        $this->actingAs($admin)->patch(route('admin.users.update', $admin), $this->accessData())->assertForbidden();

        $this->assertTrue($admin->fresh()->active);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('invalidAccessChanges')]
    public function test_invalid_access_changes_do_not_modify_the_account(array $changes, string $field): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        $target = User::factory()->withRole('buyer')->create();

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [...$this->accessData(), ...$changes])
            ->assertSessionHasErrors($field);

        $this->assertTrue($target->fresh()->active);
        $this->assertTrue($target->hasRole('buyer'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidAccessChanges(): array
    {
        return [
            'invalid role' => [['role' => 'super-admin'], 'role'],
            'missing reason' => [['reason' => ''], 'reason'],
            'invalid active flag' => [['active' => 'maybe'], 'active'],
        ];
    }

    public function test_changing_staff_to_buyer_creates_a_pending_profile(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        $target = User::factory()->withRole('coordinator')->create();

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            ...$this->accessData(), 'active' => true, 'role' => 'buyer',
        ])->assertSessionHas('status');

        $this->assertSame('pending', $target->fresh()->buyer->status);
        $this->assertFalse($target->can('place-orders'));
    }

    public function test_approval_records_the_reviewer_and_repeating_it_does_not_duplicate_the_audit(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        $owner = User::factory()->withRole('buyer')->create();
        $buyer = Buyer::factory()->for($owner)->create();
        $this->travelTo(now()->startOfSecond());

        $this->actingAs($admin)->post(route('admin.buyers.approve', $buyer), ['reason' => 'Datos de prueba revisados'])
            ->assertSessionHas('status');

        $this->assertSame('approved', $buyer->fresh()->status);
        $this->assertSame($admin->id, $buyer->fresh()->approved_by);
        $this->assertTrue($buyer->fresh()->approved_at->equalTo(now()));
        $this->assertTrue($owner->can('place-orders'));

        $this->post(route('admin.buyers.approve', $buyer), ['reason' => 'Reintento de prueba'])->assertSessionHas('status');
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'buyer.approved', 'reason' => 'Datos de prueba revisados']);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertStringContainsString('cuenta de comprador fue aprobada', $owner->notifications()->first()->data['body']);
    }

    public function test_inactive_buyer_cannot_be_approved(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create();
        $owner = User::factory()->withRole('buyer')->inactive()->create();
        $buyer = Buyer::factory()->for($owner)->create();

        $this->actingAs($admin)->post(route('admin.buyers.approve', $buyer), ['reason' => 'Datos de prueba revisados'])
            ->assertSessionHasErrors('buyer');

        $this->assertSame('pending', $buyer->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_names_are_escaped_in_the_dashboard_and_admin_list(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $name = '<script>alert("example")</script>';
        $admin = User::factory()->withRole('admin')->create(['name' => $name]);

        $this->actingAs($admin)->get(route('dashboard'))->assertSee($name)->assertDontSee($name, false);
        $this->get(route('admin.users.index'))->assertSee($name)->assertDontSee($name, false);
    }

    private function accessData(): array
    {
        return ['active' => false, 'role' => 'coordinator', 'reason' => 'Cambio de equipo de prueba'];
    }
}
