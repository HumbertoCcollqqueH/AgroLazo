<?php

namespace App\Console\Commands;

use App\Models\Producer;
use App\Models\User;
use App\Services\Messaging\ProducerWelcome;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class WelcomeProducer extends Command
{
    protected $signature = 'messaging:welcome-producer {producer : ID del productor registrado} {--actor= : ID del operador responsable} {--send : Encola una única bienvenida real; sin esta opción solo muestra el texto}';

    protected $description = 'Prepara o encola una bienvenida explícita al productor, sin aceptar consentimiento en su nombre';

    public function handle(ProducerWelcome $welcome): int
    {
        $producer = Producer::query()->findOrFail($this->argument('producer'));
        $this->line('Destinatario: productor #'.$producer->id.' · teléfono •••• '.substr($producer->phone, -4));
        if (! $this->option('send')) {
            $this->line($welcome->text($producer));
            $this->info('Vista previa. No se ha encolado ni enviado ningún mensaje.');

            return self::SUCCESS;
        }
        if (! ctype_digit((string) $this->option('actor'))) {
            $this->error('Indica --actor con el ID del operador responsable.');

            return self::FAILURE;
        }
        $actor = User::query()->findOrFail($this->option('actor'));
        try {
            $message = $welcome->queue($actor, $producer);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }
        $this->info('Bienvenida #'.$message->id.' · conversación #'.$message->conversation_id.' · estado '.$message->fresh()->status.'. Repetir conserva el mismo mensaje.');

        return self::SUCCESS;
    }
}
