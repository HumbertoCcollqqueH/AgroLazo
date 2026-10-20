<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NativeSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        $this->withHeader('User-Agent', 'AgroLazoAndroid/1.3.2');
    }

    #[DataProvider('roles')]
    public function test_native_login_restores_each_role_after_the_server_session_is_lost(string $role): void
    {
        $user = User::factory()->withRole($role)->create();
        $name = Auth::guard()->getRecallerName();
        $response = $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
        $response->assertSessionHasNoErrors()->assertCookie($name);
        $cookie = $response->getCookie($name);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertGreaterThan(now()->addYear()->timestamp, $cookie->getExpiresTime());
        $this->assertStringNotContainsString('password', $cookie->getValue());

        $this->resetSession();
        $this->travel(2)->days();
        $response = $this->withCookie($name, $cookie->getValue())->get(route('dashboard'));
        if ($role === 'producer') {
            $response->assertRedirectToRoute('producer.home');
        } else {
            $response->assertSuccessful();
        }
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Auth::guard()->viaRemember());
    }

    public static function roles(): array
    {
        return [['buyer'], ['producer'], ['admin'], ['coordinator']];
    }

    #[DataProvider('webChoices')]
    public function test_web_still_respects_the_remember_checkbox(bool $remember): void
    {
        $user = User::factory()->create();
        $name = Auth::guard()->getRecallerName();
        $response = $this->withHeader('User-Agent', 'Browser')->post(route('login'), [
            'email' => $user->email, 'password' => 'password', 'remember' => $remember,
        ])->assertSessionHasNoErrors();
        if ($remember) {
            $response->assertCookie($name);
        } else {
            $response->assertCookieMissing($name);
        }
    }

    public static function webChoices(): array
    {
        return [[false], [true]];
    }

    public function test_native_logout_revokes_persistent_access(): void
    {
        $user = User::factory()->create();
        $name = Auth::guard()->getRecallerName();
        $cookie = $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->getCookie($name);
        $token = $user->fresh()->remember_token;
        $this->withCookie($name, $cookie->getValue())->post(route('logout'))->assertRedirectToRoute('login')->assertCookieExpired($name);
        $this->assertGuest();
        $this->assertNotSame($token, $user->fresh()->remember_token);
        $this->resetSession();
        $this->withCookie($name, $cookie->getValue())->get(route('dashboard'))->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    #[DataProvider('revocations')]
    public function test_remembered_access_does_not_bypass_account_revocation(string $change): void
    {
        $user = User::factory()->create();
        $name = Auth::guard()->getRecallerName();
        $cookie = $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->getCookie($name);
        $user->forceFill($change === 'password' ? ['password' => 'ChangedPassword2026'] : ['active' => false])->save();
        $this->resetSession();
        $this->withCookie($name, $cookie->getValue())->get(route('dashboard'))->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    public static function revocations(): array
    {
        return [['password'], ['disabled']];
    }

    public function test_failed_native_login_does_not_remember_credentials(): void
    {
        $user = User::factory()->create();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email')->assertCookieMissing(Auth::guard()->getRecallerName());
        $this->assertGuest();
    }

    public function test_only_the_app_displays_persistent_session_and_native_autofill_controls(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Recordarme')->assertSee('data-native-remember', false)
            ->assertSee('data-native-autofill', false)->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)->assertSee('Tu sesión seguirá iniciada');
        $this->withHeader('User-Agent', 'Browser')->get(route('login'))->assertOk()
            ->assertSee('Recordarme')->assertDontSee('data-native-autofill', false);
    }

    #[DataProvider('selfRegistrationRoles')]
    public function test_native_registration_also_preserves_access(string $role): void
    {
        $data = ['name' => 'Usuario Andino', 'email' => 'nuevo@example.test', 'password' => 'CultivosSeguros2026',
            'password_confirmation' => 'CultivosSeguros2026', 'accept' => '1', 'buyer_type' => 'restaurant'];
        $response = $this->post(route($role === 'producer' ? 'producer.register' : 'register'), $data);
        $response->assertSessionHasNoErrors()->assertCookie(Auth::guard()->getRecallerName());
        $user = User::query()->where('email', $data['email'])->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame([$role], $user->roles->pluck('code')->all());
    }

    public static function selfRegistrationRoles(): array
    {
        return [['buyer'], ['producer']];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_find_and_use_logout_in_the_app(string $role): void
    {
        $user = User::factory()->withRole($role)->create();
        $this->actingAs($user)->get(route('account.edit'))->assertOk()
            ->assertSee('data-native-account-menu', false)->assertSee('Cerrar sesión en este teléfono')
            ->assertSee('action="'.route('logout').'"', false);
        $this->post(route('logout'))->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    public function test_declining_credential_storage_does_not_disable_native_session_persistence(): void
    {
        $user = User::factory()->create();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password', 'remember' => '0', 'save_access' => '0'])
            ->assertSessionHasNoErrors()->assertCookie(Auth::guard()->getRecallerName());
    }

    private function resetSession(): void
    {
        $this->app['session.store']->flush();
        $this->app['cookie']->unqueue(Auth::guard()->getRecallerName());
        Auth::forgetGuards();
    }
}
