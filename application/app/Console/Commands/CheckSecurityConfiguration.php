<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckSecurityConfiguration extends Command
{
    protected $signature = 'agroconecta:security-check {--production : Evalúa los requisitos de configuración para producción}';

    protected $description = 'Revisa configuración sensible sin revelar secretos ni habilitar integraciones';

    public function handle(): int
    {
        $production = $this->option('production') || app()->environment('production');
        $checks = [
            'Clave de aplicación configurada' => filled(config('app.key')),
            'Clave de identidades configurada' => strlen((string) config('producers.lookup_key')) >= 32,
            'Sesiones cifradas' => (bool) config('session.encrypt'),
            'Cookie HttpOnly' => (bool) config('session.http_only'),
            'Cookie SameSite=Lax' => config('session.same_site') === 'lax',
            'Sesión serializada en JSON' => config('session.serialization') === 'json',
            'Archivos privados sin publicación automática' => config('filesystems.disks.local.serve') === false,
            'Cola con margen sobre timeout de exportación' => (int) config('queue.connections.redis.retry_after') > 90,
        ];
        if ($production) {
            $checks += [
                'Entorno production' => app()->environment('production'),
                'Debug desactivado' => ! config('app.debug'),
                'URL HTTPS válida' => filter_var(config('app.url'), FILTER_VALIDATE_URL) && str_starts_with((string) config('app.url'), 'https://'),
                'Cookie Secure' => (bool) config('session.secure'),
                'Simulador desactivado' => ! config('messaging.simulator_enabled'),
                'Accesos demo sin configuración' => blank(config('agroconecta.demo_password')),
                'Sin archivo hot de Vite' => ! file_exists(public_path('hot')),
                'Auto verificación desactivada' => ! config('agroconecta.auto_verification_enabled'),
            ];
        }
        $this->table(['Control'.($production ? ' de producción' : ' local'), 'Estado'], collect($checks)->map(fn ($ok, $label) => [$label, $ok ? 'OK' : 'REVISAR'])->values()->all());
        $this->line('Esta comprobación no acredita HTTPS desplegado, restauración externa ni conexión real con proveedores.');

        return in_array(false, array_map(fn ($v) => (bool) $v, $checks), true) ? self::FAILURE : self::SUCCESS;
    }
}
