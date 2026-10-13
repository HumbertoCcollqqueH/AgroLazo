<?php

namespace App\Console\Commands;

use App\Services\Messaging\EvolutionConnectionManager;
use Illuminate\Console\Command;

class CheckMessagingConfiguration extends Command
{
    protected $signature = 'messaging:check';

    protected $description = 'Revisa la preparación de WhatsApp y SMS sin revelar secretos ni enviar mensajes';

    public function handle(): int
    {
        $connection = app(EvolutionConnectionManager::class);
        $rows = [
            ['Envíos SMS', config('messaging.live_enabled') && config('messaging.twilio.enabled') ? 'Habilitados por configuración' : 'Desactivados'],
            ['Conexión WhatsApp', $connection->isQr() ? 'Evolution / Baileys por QR' : 'API oficial de Meta'],
            ['Respuestas WhatsApp', $connection->automationEnabled() ? 'Activadas' : 'Desactivadas'],
            ['Estado WhatsApp', $connection->current()?->status ?? 'Sin conexión web configurada'],
            ['Solo teléfonos registrados', config('messaging.registered_contacts_only') ? 'Sí' : 'No; prerregistro abierto'],
            ['Simulador', config('messaging.simulator_enabled') && app()->environment(['local', 'testing']) ? 'Disponible' : 'Desactivado'],
            ['Aviso WhatsApp', $connection->noticeApproved() ? 'Marcado como aprobado' : 'Pendiente'],
            ['Aviso SMS', config('messaging.notice_approved') ? 'Marcado como aprobado' : 'Pendiente'],
        ];
        $missing = 0;
        foreach (['whatsapp' => ['app_secret', 'verify_token', 'access_token', 'phone_number_id', 'business_account_id', 'public_url'], 'evolution' => ['url', 'api_key', 'instance', 'webhook_secret'], 'twilio' => ['account_sid', 'auth_token', 'from', 'inbound_url', 'status_url']] as $group => $fields) {
            foreach ($fields as $field) {
                if ($group === 'whatsapp' && $connection->isQr()) {
                    continue;
                }
                $ready = filled(config('messaging.'.$group.'.'.$field));
                $rows[] = [$group.'.'.$field, $ready ? 'Configurado (sin prueba de conexión)' : 'Pendiente'];
                if (! $ready && ($group !== 'twilio' ? $connection->enabled() : config('messaging.twilio.enabled'))) {
                    $missing++;
                }
            }
        }
        $this->table(['Configuración', 'Estado'], $rows);
        $this->warn('Twilio declara que no ofrece SMS bidireccional en Perú. SMS_TWO_WAY_VERIFIED requiere comprobar una cuenta, número y país compatibles.');
        $this->line('Un HTTP aceptado o una instancia conectada no prueban entrega real. La ventana de 24 horas y las plantillas corresponden al modo oficial de Meta.');

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
