<?php

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckDeploymentHealth extends Command
{
    protected $signature = 'agroconecta:ready {--core : Comprueba dependencias y esquema sin esperar los procesos}';

    protected $description = 'Comprueba dependencias, migraciones y procesos sin mostrar credenciales.';

    public function handle(SystemHealthService $health): int
    {
        $checks = $health->snapshot();
        if ($this->option('core')) {
            unset($checks['queue'], $checks['scheduler']);
        }
        $checks['schema'] = ['label' => 'Migraciones', 'healthy' => false, 'detail' => 'Esquema pendiente o no disponible.'];
        try {
            $expected = array_map(fn ($file): string => basename($file, '.php'), glob(database_path('migrations/*.php')));
            $applied = DB::table('migrations')->pluck('migration')->all();
            $checks['schema']['healthy'] = array_diff($expected, $applied) === [];
            $checks['schema']['detail'] = $checks['schema']['healthy'] ? 'Todas las migraciones de esta imagen están aplicadas.' : 'Hay migraciones pendientes.';
        } catch (Throwable) {
        }
        $this->table(['Control', 'Estado', 'Detalle'], array_map(fn ($check): array => [$check['label'], $check['healthy'] ? 'OK' : 'REVISAR', $check['detail']], array_values($checks)));

        return collect($checks)->every(fn ($check): bool => $check['healthy']) ? self::SUCCESS : self::FAILURE;
    }
}
