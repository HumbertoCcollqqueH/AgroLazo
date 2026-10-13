<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdministratorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $passwordPath;

    private string $password = 'Synthetic-Initial!2026-secure';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->passwordPath = tempnam(sys_get_temp_dir(), 'admin-test-');
        file_put_contents($this->passwordPath, $this->password);
    }

    protected function tearDown(): void
    {
        if (is_file($this->passwordPath)) {
            unlink($this->passwordPath);
        }
        parent::tearDown();
    }

    private function bootstrapOptions(array $extra = []): array
    {
        return ['--email' => 'initial@example.test', '--name' => 'Administración Sintética', '--password-file' => $this->passwordPath, ...$extra];
    }

    public function test_bootstrap_creates_first_administrator_and_never_resets_existing_credentials(): void
    {
        $this->assertSame(0, Artisan::call('agroconecta:bootstrap-admin', $this->bootstrapOptions()));
        $this->assertStringNotContainsString($this->password, Artisan::output());
        $user = User::query()->sole();
        $hash = $user->password;
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check($this->password, $hash));
        $this->assertSame(1, Artisan::call('agroconecta:bootstrap-admin', $this->bootstrapOptions()));
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.bootstrapped']);
    }

    public function test_existing_administrator_or_existing_buyer_email_cannot_be_promoted_by_bootstrap(): void
    {
        $buyer = User::factory()->withRole('buyer')->create(['email' => 'initial@example.test']);
        $this->assertSame(1, Artisan::call('agroconecta:bootstrap-admin', $this->bootstrapOptions()));
        $this->assertFalse($buyer->fresh()->hasRole('admin'));
        User::factory()->withRole('admin')->create();
        $this->assertSame(1, Artisan::call('agroconecta:bootstrap-admin', $this->bootstrapOptions(['--email' => 'another@example.test'])));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_invalid_password_or_missing_secret_file_does_not_create_an_account(): void
    {
        file_put_contents($this->passwordPath, 'short');
        $this->assertSame(1, Artisan::call('agroconecta:bootstrap-admin', $this->bootstrapOptions()));
        $this->assertSame(1, Artisan::call('agroconecta:bootstrap-admin', $this->bootstrapOptions(['--password-file' => $this->passwordPath.'-absent'])));
        $this->assertDatabaseCount('users', 0);
    }
}
