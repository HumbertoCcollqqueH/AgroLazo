<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentHealthTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function dependencies(bool $workers): void
    {
        $this->mock(SystemHealthService::class, function ($mock) use ($workers): void {
            $mock->shouldReceive('snapshot')->once()->andReturn([
                'database' => ['label' => 'PostgreSQL', 'healthy' => true, 'detail' => 'ok'],
                'cache' => ['label' => 'Redis', 'healthy' => true, 'detail' => 'ok'],
                'queue' => ['label' => 'Worker', 'healthy' => $workers, 'detail' => 'prueba'],
                'scheduler' => ['label' => 'Scheduler', 'healthy' => $workers, 'detail' => 'prueba'],
            ]);
        });
    }

    public function test_full_health_requires_current_workers(): void
    {
        $this->dependencies(false);
        $this->artisan('agroconecta:ready')->assertFailed();
    }

    public function test_core_health_accepts_migrated_database_before_workers_start(): void
    {
        $this->dependencies(false);
        $this->artisan('agroconecta:ready --core')->assertSuccessful();
    }

    public function test_pending_release_migration_fails_readiness(): void
    {
        DB::table('migrations')->where('migration', '2026_09_18_234207_create_report_exports_table')->delete();
        $this->dependencies(true);
        $this->artisan('agroconecta:ready')->assertFailed();
    }
}
