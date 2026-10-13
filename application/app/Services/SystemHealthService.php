<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemHealthService
{
    /** @return array<string, array{label: string, healthy: bool, detail: string}> */
    public function snapshot(): array
    {
        $checks = [
            'database' => ['label' => 'PostgreSQL', 'healthy' => false, 'detail' => 'No se pudo comprobar la conexión.'],
            'cache' => ['label' => 'Redis', 'healthy' => false, 'detail' => 'No se pudo comprobar la lectura y escritura.'],
            'queue' => ['label' => 'Worker', 'healthy' => false, 'detail' => 'Esperando un trabajo procesado recientemente.'],
            'scheduler' => ['label' => 'Scheduler', 'healthy' => false, 'detail' => 'Esperando la próxima ejecución programada.'],
        ];

        try {
            DB::select('SELECT 1');
            $checks['database']['healthy'] = true;
            $checks['database']['detail'] = 'Conexión real con la base de datos.';
        } catch (Throwable) {
            // Public diagnostics intentionally exclude connection details and exception messages.
        }

        try {
            $cache = Cache::store('redis');
            $key = 'system:cache-probe:'.bin2hex(random_bytes(8));
            $cache->put($key, 'ok', 10);
            $checks['cache']['healthy'] = $cache->pull($key) === 'ok';
            $checks['cache']['detail'] = $checks['cache']['healthy'] ? 'Lectura y escritura verificadas.' : 'No se pudo recuperar la comprobación.';

            foreach (['queue', 'scheduler'] as $process) {
                $lastRun = $cache->get('system:'.$process.':heartbeat');
                if (is_numeric($lastRun) && time() - (int) $lastRun >= 0 && time() - (int) $lastRun < 150) {
                    $checks[$process]['healthy'] = true;
                    $checks[$process]['detail'] = 'Última señal hace '.(time() - (int) $lastRun).' s.';
                }
            }
        } catch (Throwable) {
            // A Redis outage also makes process heartbeats unavailable.
        }

        return $checks;
    }
}
