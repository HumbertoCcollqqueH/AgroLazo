<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileEntryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_enters_without_server_configuration(): void
    {
        $this->get('/app')->assertOk()->assertViewIs('auth.app-welcome')
            ->assertSee(route('login'))->assertSee(route('register'))
            ->assertDontSee('Configurar servidor')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    #[DataProvider('roles')]
    public function test_each_role_enters_its_own_workspace(string $role, string $route): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->withRole($role)->create())->get('/app')->assertRedirectToRoute($route);
    }

    public static function roles(): array
    {
        return [['buyer', 'marketplace.index'], ['producer', 'producer.home'], ['admin', 'marketplace.index'], ['coordinator', 'marketplace.index']];
    }

    public function test_only_trusted_proxy_can_provide_https_and_cannot_override_host(): void
    {
        $this->app->instance('env', 'production');
        config(['app.url' => 'https://agro.example.test', 'session.secure' => true, 'session.http_only' => true, 'session.encrypt' => true, 'session.same_site' => 'lax']);
        TrustProxies::at(['172.19.0.0/16']);
        Route::get('/mobile-proxy-client', fn () => response(request()->ip()));

        try {
            $this->withServerVariables(['REMOTE_ADDR' => '172.19.0.8'])
                ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example'])
                ->get('http://agro.example.test/app')->assertOk()
                ->assertSee('https://agro.example.test/login')->assertDontSee('attacker.example')
                ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

            $this->withHeader('X-Forwarded-For', '203.0.113.5, 198.51.100.25')
                ->get('http://agro.example.test/mobile-proxy-client')->assertOk()->assertContent('198.51.100.25');

            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
                ->get('http://agro.example.test/app')->assertStatus(426);
            $this->withHeader('X-Forwarded-For', '203.0.113.5')
                ->get('https://agro.example.test/mobile-proxy-client')->assertOk()->assertContent('198.51.100.25');
        } finally {
            TrustProxies::at([]);
        }
    }

    public function test_android_buyer_has_navigation_without_changing_desktop_layout(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->withRole('buyer')->create())
            ->withHeader('User-Agent', 'AgroLazoAndroid/1.1')->get(route('marketplace.index'))
            ->assertOk()->assertSee('Secciones de la aplicación')->assertSee('app-bottom-nav');
        $this->withHeader('User-Agent', 'Browser')->get(route('marketplace.index'))->assertOk()->assertDontSee('app-bottom-nav');
    }

    #[DataProvider('operatorRoles')]
    public function test_operators_can_move_between_the_store_and_their_panel_on_mobile(string $role): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->withRole($role)->create();
        $this->withHeader('User-Agent', 'AgroLazoAndroid/1.2.1')
            ->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirectToRoute('marketplace.index');
        $this->get(route('marketplace.index'))->assertOk()->assertSee('op-mobile-nav')
            ->assertSee(route('dashboard'))->assertSee('Mi panel');
        $this->get(route('dashboard'))->assertOk()->assertSee('Inicio AgroLazo')
            ->assertSee('data-operations-close', false)->assertSee('op-mobile-nav');
    }

    public static function operatorRoles(): array
    {
        return [['admin'], ['coordinator']];
    }
}
