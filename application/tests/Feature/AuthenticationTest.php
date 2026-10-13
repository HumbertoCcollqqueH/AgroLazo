<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_can_open_access_forms_and_are_redirected_from_the_dashboard(): void
    {
        $this->get(route('login'))->assertViewIs('auth.login');
        $this->get(route('register'))->assertViewIs('auth.register');
        $this->get(route('password.request'))->assertViewIs('auth.forgot-password');
        $this->get(route('dashboard'))->assertRedirectToRoute('login');
    }

    public function test_registration_creates_only_a_pending_buyer_and_encrypts_contact_phone(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $response = $this->post(route('register'), [
            ...$this->registrationData(),
            'email' => '  Buyer@Example.test ',
            'phone' => '987 654 321',
            'role' => 'admin',
            'roles' => ['admin'],
            'active' => false,
            'status' => 'approved',
            'permissions' => ['confirm-payments'],
        ]);

        $response->assertRedirectToRoute('marketplace.index');
        $user = User::query()->where('email', 'buyer@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->active);
        $this->assertSame(['buyer'], $user->roles->pluck('code')->all());
        $this->assertSame('pending', $user->buyer->status);
        $this->assertSame('+51987654321', $user->buyer->phone);
        $this->assertNotSame('+51987654321', DB::table('buyers')->where('id', $user->buyer->id)->value('phone'));
        $this->assertTrue(Hash::check('TestingSecure123', $user->password));
        $this->assertFalse($user->can('place-orders'));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'buyer.registered']);
        $this->assertStringNotContainsString('TestingSecure123', DB::table('audit_logs')->value('changes'));
    }

    public function test_registration_rejects_a_duplicate_email_after_normalization(): void
    {
        User::factory()->create(['email' => 'buyer@example.test']);

        $this->post(route('register'), [...$this->registrationData(), 'email' => 'BUYER@EXAMPLE.TEST'])
            ->assertSessionHasErrors(['email' => 'Este correo electrónico ya está registrado.']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('buyers', 0);
        $this->assertGuest();
    }

    #[DataProvider('invalidRegistrationData')]
    public function test_registration_rejects_invalid_details_without_creating_a_user(array $changes, string $field): void
    {
        $this->post(route('register'), [...$this->registrationData(), ...$changes])->assertSessionHasErrors($field);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertGuest();
    }

    public static function invalidRegistrationData(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'invalid email' => [['email' => 'invalid'], 'email'],
            'unknown buyer type' => [['buyer_type' => 'administrator'], 'buyer_type'],
            'invalid phone' => [['phone' => '123'], 'phone'],
            'short password' => [['password' => 'Short1', 'password_confirmation' => 'Short1'], 'password'],
            'password confirmation differs' => [['password_confirmation' => 'WrongSecure123'], 'password'],
            'bcrypt byte limit' => [['password' => str_repeat('É', 36).'a1', 'password_confirmation' => str_repeat('É', 36).'a1'], 'password'],
        ];
    }

    public function test_active_users_can_login_with_normalized_email_and_logout(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.test', 'password' => 'TestingSecure123']);

        $this->post(route('login'), ['email' => ' BUYER@example.test ', 'password' => 'TestingSecure123', 'remember' => '1'])
            ->assertRedirectToRoute('dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->post(route('logout'))->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    public function test_inactive_users_cannot_login(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'TestingSecure123']);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'TestingSecure123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_login_blocks_after_five_failed_attempts_and_allows_a_later_valid_attempt(): void
    {
        $user = User::factory()->create(['email' => 'limited@example.test', 'password' => 'TestingSecure123']);
        $this->freezeTime();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }

        $this->post(route('login'), ['email' => $user->email, 'password' => 'TestingSecure123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertTrue(RateLimiter::tooManyAttempts('login:'.hash('sha256', 'limited@example.test|127.0.0.1'), 5));

        $this->travel(61)->seconds();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'TestingSecure123'])->assertRedirectToRoute('dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_open_session_is_closed_after_the_account_is_disabled(): void
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirectToRoute('login');

        $this->assertGuest();
    }

    private function registrationData(): array
    {
        return [
            'name' => 'Comprador de prueba',
            'email' => 'buyer@example.test',
            'buyer_type' => 'restaurant',
            'password' => 'TestingSecure123',
            'password_confirmation' => 'TestingSecure123',
        ];
    }
}
