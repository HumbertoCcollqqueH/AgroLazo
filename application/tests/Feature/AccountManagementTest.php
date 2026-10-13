<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutVite();
    }

    #[DataProvider('roles')]
    public function test_admin_creates_an_active_user_with_exactly_the_requested_role(string $role): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->assertSee('Contraseña inicial');
        $this->post(route('admin.users.store'), $this->creationData([
            'role' => $role, 'email' => '  NUEVA@EXAMPLE.TEST  ',
            'active' => false, 'status' => 'approved', 'roles' => ['admin'],
        ]))->assertRedirectToRoute('admin.users.index');

        $created = User::query()->where('email', 'nueva@example.test')->firstOrFail();
        $this->assertTrue($created->active);
        $this->assertTrue(Hash::check('NewSecurePassword123', $created->password));
        $this->assertSame([$role], $created->roles->pluck('code')->all());
        $this->assertSame($role === 'buyer', $created->buyer()->exists());
        if ($role === 'buyer') {
            $this->assertSame('pending', $created->buyer->status);
            $this->assertFalse($created->can('place-orders'));
        }
        $audit = AuditLog::query()->where('action', 'user.created')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertStringNotContainsString('NewSecurePassword123', json_encode($audit->changes));
        $this->assertArrayNotHasKey('password', $audit->changes);
        $this->get(route('admin.users.index'))->assertOk()->assertSee('Nueva cuenta');
        Auth::logout();
        $this->post(route('login'), ['email' => $created->email, 'password' => 'NewSecurePassword123'])->assertRedirect();
        $this->assertAuthenticatedAs($created);
    }

    public static function roles(): array
    {
        return [['admin'], ['coordinator'], ['buyer']];
    }

    public function test_guests_non_admins_and_inactive_admins_cannot_create_users(): void
    {
        $this->get(route('admin.users.create'))->assertRedirectToRoute('login');
        $this->post(route('admin.users.store'), $this->creationData())->assertRedirectToRoute('login');
        foreach (['buyer', 'coordinator'] as $role) {
            $user = User::factory()->withRole($role)->create();
            $this->actingAs($user)->get(route('admin.users.create'))->assertForbidden();
            $this->post(route('admin.users.store'), $this->creationData())->assertForbidden();
        }
        $inactive = User::factory()->withRole('admin')->inactive()->create();
        $this->actingAs($inactive)->postJson(route('admin.users.store'), $this->creationData())->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'nueva@example.test']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('invalidCreation')]
    public function test_invalid_creation_never_creates_a_partial_account(array $overrides, string $field): void
    {
        $admin = User::factory()->withRole('admin')->create(['email' => 'existing@example.test']);
        $this->actingAs($admin)->post(route('admin.users.store'), $this->creationData($overrides))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSessionDoesntHavePassword();
    }

    public static function invalidCreation(): array
    {
        return [
            'duplicate normalized email' => [['email' => ' EXISTING@EXAMPLE.TEST '], 'email'],
            'unsupported role' => [['role' => 'superadmin'], 'role'],
            'weak password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'confirmation mismatch' => [['password_confirmation' => 'WrongPassword123'], 'password'],
            'multibyte bcrypt overflow' => [['password' => str_repeat('ñ', 36).'A1', 'password_confirmation' => str_repeat('ñ', 36).'A1'], 'password'],
            'missing reason' => [['reason' => ''], 'reason'],
        ];
    }

    public function test_personalizing_a_demo_account_preserves_its_id_role_history_and_password_and_survives_reseeding(): void
    {
        config(['agroconecta.demo_password' => 'DemoPassword123']);
        $this->seed(DemoUsersSeeder::class);
        $admin = User::query()->where('email', 'admin@agroconecta.test')->firstOrFail();
        $oldHash = $admin->password;
        $this->actingAs($admin)->get(route('account.edit'))->assertOk()->assertSee('Administrador con acceso completo');
        $this->patch(route('account.update'), [
            'name' => 'Administración principal', 'email' => ' ADMIN@EMPRESA.TEST ',
            'current_password' => 'DemoPassword123', 'password' => '',
            'role' => 'buyer', 'active' => false, 'id' => 99999,
        ])->assertRedirectToRoute('account.edit');
        $admin->refresh();
        $this->assertSame('admin@empresa.test', $admin->email);
        $this->assertSame('Administración principal', $admin->name);
        $this->assertSame($oldHash, $admin->password);
        $this->assertTrue($admin->active);
        $this->assertTrue($admin->can('manage-users'));
        $this->assertNull($admin->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.demo_created', 'subject_id' => $admin->id]);

        config(['agroconecta.demo_password' => null]);
        $this->seed(DemoUsersSeeder::class);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseMissing('users', ['email' => 'admin@agroconecta.test']);
        $this->assertSame($oldHash, $admin->fresh()->password);
        $this->get(route('account.edit'))->assertOk()->assertSee('admin@empresa.test');
    }

    public function test_password_update_keeps_current_access_and_invalidates_a_previous_session_without_logging_secrets(): void
    {
        $user = User::factory()->withRole('admin')->create();
        $oldHash = $user->password;
        $oldToken = $user->remember_token;
        $this->actingAs($user)->patch(route('account.update'), [
            'name' => $user->name, 'email' => $user->email, 'current_password' => 'password',
            'password' => 'ReplacementSecure123', 'password_confirmation' => 'ReplacementSecure123',
        ])->assertRedirectToRoute('account.edit');
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('ReplacementSecure123', $fresh->password));
        $this->assertNotSame($oldToken, $fresh->remember_token);
        $this->get(route('account.edit'))->assertOk();
        $audit = AuditLog::query()->where('action', 'user.profile_updated')->firstOrFail();
        $this->assertTrue($audit->changes['password_changed']);
        $this->assertStringNotContainsString('ReplacementSecure123', json_encode($audit->changes));
        $this->assertArrayNotHasKey('current_password', $audit->changes);

        $this->actingAs($fresh)->withSession(['password_hash_web' => $oldHash])
            ->get(route('account.edit'))->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    #[DataProvider('invalidProfile')]
    public function test_invalid_profile_updates_leave_the_account_unchanged(array $overrides, string $field): void
    {
        $user = User::factory()->withRole('buyer')->create();
        User::factory()->create(['email' => 'existing@example.test']);
        $before = $user->only(['name', 'email', 'password']);
        $this->actingAs($user)->patch(route('account.update'), [
            'name' => 'Nuevo nombre', 'email' => 'changed@example.test', 'current_password' => 'password',
            ...$overrides,
        ])->assertSessionHasErrors($field);
        $this->assertSame($before, $user->fresh()->only(['name', 'email', 'password']));
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSessionDoesntHavePassword();
    }

    public static function invalidProfile(): array
    {
        return [
            'wrong current password' => [['current_password' => 'wrong'], 'current_password'],
            'missing current password' => [['current_password' => ''], 'current_password'],
            'duplicate email' => [['email' => ' EXISTING@EXAMPLE.TEST '], 'email'],
            'weak replacement' => [['password' => 'weak', 'password_confirmation' => 'weak'], 'password'],
            'multibyte overflow' => [['password' => str_repeat('ñ', 36).'A1', 'password_confirmation' => str_repeat('ñ', 36).'A1'], 'password'],
        ];
    }

    public function test_profile_routes_require_an_active_authenticated_account(): void
    {
        $this->get(route('account.edit'))->assertRedirectToRoute('login');
        $this->patch(route('account.update'), [])->assertRedirectToRoute('login');
        $user = User::factory()->inactive()->create();
        $this->actingAs($user)->patchJson(route('account.update'), [])->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function creationData(array $overrides = []): array
    {
        return [
            'name' => 'Nueva cuenta', 'email' => 'nueva@example.test', 'role' => 'buyer',
            'password' => 'NewSecurePassword123', 'password_confirmation' => 'NewSecurePassword123',
            'reason' => 'Ingreso autorizado al equipo', ...$overrides,
        ];
    }

    private function assertSessionDoesntHavePassword(): void
    {
        $oldInput = session()->getOldInput();
        foreach (['password', 'password_confirmation', 'current_password'] as $field) {
            $this->assertArrayNotHasKey($field, $oldInput);
        }
    }
}
