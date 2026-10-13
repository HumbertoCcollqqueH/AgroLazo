<?php

namespace App\Console\Commands;

use App\Jobs\SystemHealthProbe;
use App\Services\SystemHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

#[Signature('agroconecta:health {--wait=75 : Segundos máximos de espera (0 a 90)}')]
#[Description('Comprueba PostgreSQL, Redis, un trabajo real y la ejecución del scheduler')]
class CheckSystemHealth extends Command
{
    public function handle(SystemHealthService $health): int
    {
        $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 90]]);
        if ($wait === false) {
            $this->error('Usa --wait con un número entre 0 y 90.');

            return self::INVALID;
        }

        if (config('queue.default') !== 'redis') {
            $this->error('Esta comprobación requiere QUEUE_CONNECTION=redis para verificar un worker real.');

            return self::FAILURE;
        }

        $this->info('Comprobando servicios. El scheduler puede tardar hasta el próximo minuto.');
        $probeId = (string) Str::uuid();
        $probeKey = 'system:queue:probe:'.$probeId;
        $deadline = microtime(true) + $wait;

        try {
            SystemHealthProbe::dispatch($probeId)->onConnection('redis');
            do {
                $checks = $health->snapshot();
                $checks['queue']['healthy'] = Cache::store('redis')->get($probeKey) === true;
                $checks['queue']['detail'] = $checks['queue']['healthy']
                    ? 'El worker procesó el trabajo de esta comprobación.'
                    : 'El trabajo de esta comprobación sigue pendiente.';

                if (collect($checks)->every(fn (array $check): bool => $check['healthy'])) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    break;
                }
                usleep(500_000);
            } while (true);

            Cache::store('redis')->forget($probeKey);
        } catch (Throwable) {
            $checks = $health->snapshot();
            $checks['queue']['healthy'] = false;
            $checks['queue']['detail'] = 'No se pudo completar el trabajo de prueba.';
        }

        $this->table(['Servicio', 'Estado', 'Comprobación'], array_map(
            fn (array $check): array => [$check['label'], $check['healthy'] ? 'OK' : 'REVISAR', $check['detail']],
            array_values($checks),
        ));

        return collect($checks)->every(fn (array $check): bool => $check['healthy']) ? self::SUCCESS : self::FAILURE;
    }
}
