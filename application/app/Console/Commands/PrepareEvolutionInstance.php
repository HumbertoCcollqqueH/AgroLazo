<?php

namespace App\Console\Commands;

use App\Services\Messaging\EvolutionConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PrepareEvolutionInstance extends Command
{
    protected $signature = 'messaging:prepare-evolution {--apply : Crea la instancia oficial y configura el callback interno}';

    protected $description = 'Prepara Evolution con WHATSAPP-BUSINESS; no envía mensajes ni genera QR';

    public function handle(): int
    {
        if (app(EvolutionConnectionManager::class)->isQr()) {
            $this->error('La conexión activa utiliza QR. Adminístrala desde la página WhatsApp.');

            return self::FAILURE;
        }
        foreach (['evolution.url', 'evolution.api_key', 'evolution.instance', 'evolution.webhook_secret', 'whatsapp.access_token', 'whatsapp.phone_number_id', 'whatsapp.business_account_id', 'whatsapp.public_url', 'whatsapp.app_secret', 'whatsapp.verify_token'] as $key) {
            if (! filled(config('messaging.'.$key))) {
                $this->error('Falta configurar '.$key.'. Ejecuta messaging:check.');

                return self::FAILURE;
            }
        }
        if (! str_starts_with((string) config('messaging.whatsapp.public_url'), 'https://')) {
            $this->error('El webhook público de Meta debe usar HTTPS.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Configuración disponible. Usa --apply para crear la instancia WHATSAPP-BUSINESS y su callback interno. No se enviarán mensajes.');

            return self::SUCCESS;
        }
        $client = Http::baseUrl(rtrim((string) config('messaging.evolution.url'), '/'))->withHeaders(['apikey' => config('messaging.evolution.api_key')])->acceptJson()->timeout(20)->withoutRedirecting();
        $name = (string) config('messaging.evolution.instance');
        try {
            $instances = $client->get('/instance/fetchInstances');
            if (! $instances->successful() || ! is_array($instances->json())) {
                $this->error('No se pudo consultar la instancia.');

                return self::FAILURE;
            }
            $existing = collect($instances->json())->first(fn ($row): bool => ($row['name'] ?? $row['instance']['instanceName'] ?? null) === $name);
            if ($existing && ($existing['integration'] ?? $existing['instance']['integration'] ?? null) !== 'WHATSAPP-BUSINESS') {
                $this->error('La instancia existente no es WHATSAPP-BUSINESS. Usa otro nombre; no se modificó.');

                return self::FAILURE;
            }
            if (! $existing) {
                $created = $client->post('/instance/create', ['instanceName' => $name, 'integration' => 'WHATSAPP-BUSINESS', 'qrcode' => false, 'token' => config('messaging.whatsapp.access_token'), 'number' => config('messaging.whatsapp.phone_number_id'), 'businessId' => config('messaging.whatsapp.business_account_id')]);
                if (! $created->successful() || $created->json('error')) {
                    $this->error('Evolution no confirmó la creación. Revisa la instancia antes de repetir.');

                    return self::FAILURE;
                }
            }
            $callback = $client->post('/webhook/set/'.rawurlencode($name), ['webhook' => ['enabled' => true, 'url' => config('messaging.evolution.callback_url'), 'headers' => ['X-Evolution-Secret' => config('messaging.evolution.webhook_secret'), 'Host' => parse_url((string) config('app.url'), PHP_URL_HOST)], 'webhookByEvents' => false, 'webhookBase64' => false, 'events' => ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'SEND_MESSAGE', 'CONNECTION_UPDATE']]]);
            if (! $callback->successful() || $callback->json('error')) {
                $this->error('La instancia existe pero el callback no quedó confirmado.');

                return self::FAILURE;
            }
        } catch (ConnectionException) {
            $this->error('Respuesta incierta de Evolution. Consulta la instancia antes de repetir.');

            return self::FAILURE;
        }
        $this->info('Instancia oficial y callback interno preparados. Configura Meta con WHATSAPP_PUBLIC_WEBHOOK_URL y META_VERIFY_TOKEN. La entrega real sigue pendiente de probar.');

        return self::SUCCESS;
    }
}
