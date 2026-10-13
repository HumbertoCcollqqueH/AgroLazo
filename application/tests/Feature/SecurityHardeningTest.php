<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['producers.lookup_key' => str_repeat('a', 64)]);
    }

    public function test_security_headers_cover_public_pages_and_errors(): void
    {
        foreach (['/login', '/not-a-route'] as $path) {
            $response = $this->get($path);
            $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
            $csp = $response->headers->get('Content-Security-Policy');
            $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
            $this->assertStringContainsString('https://tile.openstreetmap.org', $csp);
            $this->assertStringContainsString("script-src 'self'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringNotContainsString('unsafe-inline', $csp);
        }
    }

    public function test_host_injection_is_rejected_before_password_links_can_be_generated(): void
    {
        $this->get('http://attacker.example/login')->assertStatus(400)->assertDontSee('attacker.example');
        $this->withHeaders(['X-Forwarded-Host' => 'attacker.example'])->get('http://localhost/login')->assertOk()->assertDontSee('attacker.example');
    }

    public function test_real_csrf_middleware_rejects_a_missing_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/login', ['email' => 'anything@example.test', 'password' => 'not-logged'])->assertStatus(419);
    }

    public function test_mutating_request_budget_covers_routes_without_specific_throttles(): void
    {
        config(['security.write_limit' => 2]);
        $user = User::factory()->withRole('admin')->create();
        Route::middleware(['web', 'auth'])->post('/security-test-write', fn () => response('ok'));
        $this->actingAs($user)->post('/security-test-write')->assertOk();
        $this->post('/security-test-write')->assertOk();
        $this->post('/security-test-write')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_webhook_budget_does_not_accept_unverified_requests(): void
    {
        config(['security.webhook_limit' => 2, 'messaging.whatsapp.enabled' => false]);
        $this->postJson('/api/v1/webhooks/whatsapp/meta', [])->assertStatus(503);
        $this->postJson('/api/v1/webhooks/whatsapp/meta', [])->assertStatus(503);
        $this->postJson('/api/v1/webhooks/whatsapp/meta', [])->assertStatus(429);
    }

    public function test_rejection_log_omits_credentials_tokens_body_and_raw_ip(): void
    {
        $logger = Mockery::mock();
        Log::shouldReceive('channel')->with('security')->andReturn($logger);
        $logger->shouldReceive('notice')->once()->with('request_rejected', Mockery::on(function (array $context): bool {
            $this->assertEqualsCanonicalizing(['status', 'method', 'route', 'actor_id', 'client_fingerprint'], array_keys($context));
            $this->assertSame(400, $context['status']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $context['client_fingerprint']);
            foreach (['super-secret', 'token-private', '00000003', '127.0.0.1'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($context));
            }

            return true;
        }));
        $this->post('http://attacker.example/login?token=token-private', ['password' => 'super-secret', 'dni' => '00000003'])->assertStatus(400);
    }

    public function test_personal_pages_are_not_cached_and_html_is_escaped(): void
    {
        $user = User::factory()->withRole('buyer')->create(['name' => '<script>alert(1)</script>']);
        Buyer::factory()->create(['user_id' => $user->id]);
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->get('/storage/reports/unknown.csv')->assertNotFound();
    }

    public function test_production_requires_safe_configuration_and_hides_diagnostics(): void
    {
        $this->app->instance('env', 'production');
        config(['app.debug' => true, 'app.url' => 'http://localhost']);
        $this->get('/dev/status')->assertNotFound();
        $this->get('/dev/message-simulator')->assertNotFound();
        $this->get('/login')->assertStatus(503)->assertDontSee('APP_KEY');
        $this->assertFalse(config('app.debug'));
        $this->get('/up')->assertOk();
    }

    public function test_production_requires_transport_https_and_secure_cookie(): void
    {
        $this->app->instance('env', 'production');
        config(['app.url' => 'https://agro.example.test', 'session.secure' => true, 'session.http_only' => true, 'session.encrypt' => true, 'session.same_site' => 'lax']);
        $this->get('http://agro.example.test/login')->assertStatus(426);
        $response = $this->get('https://agro.example.test/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->get('https://agro.example.test/')->assertOk()->assertViewIs('marketplace.index');
    }

    public function test_security_check_does_not_mislabel_local_configuration_as_production_ready(): void
    {
        $this->artisan('agroconecta:security-check')->assertExitCode(0);
        $this->artisan('agroconecta:security-check --production')->assertExitCode(1);
    }
}
