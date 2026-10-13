<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Mockery\MockInterface;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    public function test_local_status_renders_an_actual_failed_check_without_claiming_success(): void
    {
        $this->mock(SystemHealthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('snapshot')->once()->andReturn([
                'database' => ['label' => 'PostgreSQL', 'healthy' => false, 'detail' => 'Conexión no disponible.'],
            ]);
        });

        $this->get('/dev/status')->assertOk()
            ->assertSee('Requiere revisión')
            ->assertSee('Conexión no disponible.')
            ->assertDontSee('Entorno operativo');
    }

    public function test_diagnostics_are_not_exposed_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->get('/dev/status')->assertNotFound();
    }

    public function test_the_standard_liveness_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
