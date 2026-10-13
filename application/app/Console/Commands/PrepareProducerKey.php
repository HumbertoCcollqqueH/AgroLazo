<?php

namespace App\Console\Commands;

use App\Services\ProducerIdentity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

#[Signature('agroconecta:prepare-producer-key')]
#[Description('Prepara o verifica la clave privada de identidad de productores sin reemplazar claves existentes')]
class PrepareProducerKey extends Command
{
    public function handle(ProducerIdentity $identity): int
    {
        if (! Schema::hasTable('producers') || ! Schema::hasTable('producer_identity_keys')) {
            $this->error('Ejecuta las migraciones antes de preparar la clave de productores.');

            return self::FAILURE;
        }

        try {
            $configured = config('producers.lookup_key');
            if ($configured !== null && $configured !== '') {
                $identity->assertKeyMatchesDatabase();
                $this->info('La clave privada de productores coincide con esta base de datos y se conserva.');

                return self::SUCCESS;
            }

            if (! app()->environment(['local', 'testing'])) {
                $this->error('Configura PRODUCER_LOOKUP_KEY mediante los secretos del entorno antes de continuar.');

                return self::FAILURE;
            }
            if (app()->configurationIsCached()) {
                $this->error('Limpia la caché de configuración antes de preparar una clave local nueva.');

                return self::FAILURE;
            }

            DB::transaction(function () use ($identity): void {
                DB::statement('LOCK TABLE producer_identity_keys IN SHARE ROW EXCLUSIVE MODE');
                if (DB::table('producers')->exists() || DB::table('producer_identity_keys')->exists()) {
                    throw ValidationException::withMessages(['producer' => 'Ya existe información de identidad. Restaura PRODUCER_LOOKUP_KEY desde una copia segura; no se generó ninguna clave.']);
                }

                $path = app()->environmentFilePath();
                if (! File::exists($path) || ! is_writable($path)) {
                    throw new RuntimeException('El archivo de entorno local debe existir y permitir escritura para preparar la clave.');
                }
                $contents = File::get($path);
                if (preg_match('/^PRODUCER_LOOKUP_KEY[\t ]*=[\t ]*([^\r\n]*)/m', $contents, $match) === 1 && ! in_array(trim($match[1]), ['', '""', "''"], true)) {
                    throw new RuntimeException('El archivo de entorno contiene una clave que no está cargada. Recarga la configuración y vuelve a intentarlo.');
                }

                $key = bin2hex(random_bytes(32));
                $line = 'PRODUCER_LOOKUP_KEY='.$key;
                $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
                $contents = preg_match('/^PRODUCER_LOOKUP_KEY[\t ]*=[^\r\n]*/m', $contents) === 1
                    ? preg_replace('/^PRODUCER_LOOKUP_KEY[\t ]*=[^\r\n]*/m', $line, $contents)
                    : rtrim($contents).$newline.$line.$newline;
                $mode = fileperms($path) & 0777;
                File::replace($path, $contents, $mode);
                config(['producers.lookup_key' => $key]);
                $identity->assertKeyMatchesDatabase();
            });
            $this->info('Clave privada de productores preparada. Conserva una copia segura del archivo de entorno junto con las copias de la base de datos.');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
