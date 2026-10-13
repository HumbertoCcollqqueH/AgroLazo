<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_seeding_creates_three_roles_and_preserves_existing_passwords_on_repeat(): void
    {
        config(['agroconecta.demo_password' => 'FirstDemoPassword123']);
        $this->seed(DemoUsersSeeder::class);
        config(['agroconecta.demo_password' => 'ChangedDemoPassword123']);

        $this->seed(DemoUsersSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('audit_logs', 3);
        $admin = User::query()->where('email', 'admin@agroconecta.test')->firstOrFail();
        $this->assertTrue(Hash::check('FirstDemoPassword123', $admin->password));
        $this->assertTrue($admin->can('manage-users'));
        $this->assertDatabaseHas('buyers', ['status' => 'pending']);
    }

    public function test_demo_seeding_is_rejected_in_production_before_creating_users(): void
    {
        User::query()->count();
        config(['agroconecta.demo_password' => 'FirstDemoPassword123']);
        $this->app->instance('env', 'production');

        try {
            app(DemoUsersSeeder::class)->run();
            $this->fail('Production must reject demo data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('solo se crean en local o testing', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_seeding_requires_an_explicit_password(): void
    {
        config(['agroconecta.demo_password' => null]);

        try {
            $this->seed(DemoUsersSeeder::class);
            $this->fail('Missing demo password must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DEMO_PASSWORD', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }
}
