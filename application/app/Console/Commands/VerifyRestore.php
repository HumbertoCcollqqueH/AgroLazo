<?php

namespace App\Console\Commands;

use App\Models\OfferPhoto;
use App\Models\PaymentProof;
use App\Models\Producer;
use App\Models\ReceiptPhoto;
use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use RuntimeException;
use Throwable;

class VerifyRestore extends Command
{
    protected $signature = 'agroconecta:verify-restore {--files-root= : Carpeta privada restaurada; por defecto utiliza el disco local} {--expected-database= : Rechaza una conexión a otra base de datos}';

    protected $description = 'Genera un manifiesto de solo lectura: tablas, secuencias, cifrado y archivos privados.';

    public function handle(): int
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                throw new RuntimeException('Esta verificación requiere PostgreSQL.');
            }
            if ($this->option('expected-database') && DB::scalar('SELECT current_database()') !== $this->option('expected-database')) {
                throw new RuntimeException('La conexión no apunta a la base esperada.');
            }
            $root = realpath($this->option('files-root') ?: Storage::disk('local')->path(''));
            if ($root === false || ! is_dir($root)) {
                throw new RuntimeException('No se encuentra el almacenamiento privado.');
            }
            $outer = DB::transactionLevel() === 0;
            $manifest = DB::transaction(function () use ($root, $outer): array {
                if ($outer) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }
                $tables = [];
                foreach (Schema::getTableListing('public', false) as $table) {
                    $rows = [];
                    foreach (DB::table($table)->cursor() as $row) {
                        $values = (array) $row;
                        ksort($values);
                        $rows[] = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
                    }
                    sort($rows, SORT_STRING);
                    $tables[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode('', $rows))];
                }
                ksort($tables);
                if (! isset($tables['migrations'], $tables['orders'], $tables['producers'], $tables['report_exports'])) {
                    throw new RuntimeException('Faltan tablas de las fases requeridas.');
                }
                if (DB::table('pg_constraint')->where('convalidated', false)->whereIn('connamespace', DB::table('pg_namespace')->select('oid')->where('nspname', 'public'))->exists()) {
                    throw new RuntimeException('Hay restricciones de integridad sin validar.');
                }
                $encryptedFields = 0;
                foreach (File::files(app_path('Models')) as $file) {
                    $class = 'App\\Models\\'.$file->getBasename('.php');
                    if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                        continue;
                    }
                    $model = new $class;
                    $encrypted = array_keys(array_filter($model->getCasts(), fn ($cast): bool => is_string($cast) && str_starts_with($cast, 'encrypted')));
                    if ($encrypted === [] || ! isset($tables[$model->getTable()])) {
                        continue;
                    }
                    foreach ($class::query()->cursor() as $record) {
                        foreach ($encrypted as $attribute) {
                            if ($record->getRawOriginal($attribute) !== null) {
                                $record->getAttribute($attribute);
                                $encryptedFields++;
                            }
                        }
                    }
                }
                $lookup = (string) config('producers.lookup_key');
                if (! preg_match('/\A[a-fA-F0-9]{64}\z/D', $lookup)) {
                    throw new RuntimeException('La clave de índices privados no es válida.');
                }
                $binaryKey = hex2bin($lookup);
                $expected = hash_hmac('sha256', 'agroconecta:producer-identity:key:v1', $binaryKey);
                $stored = DB::table('producer_identity_keys')->where('id', 1)->value('fingerprint');
                if (($stored !== null && ! hash_equals($stored, $expected)) || ($stored === null && Producer::query()->exists())) {
                    throw new RuntimeException('La clave de índices privados no coincide.');
                }
                foreach (Producer::query()->cursor() as $producer) {
                    foreach (['dni', 'phone'] as $attribute) {
                        $fingerprint = hash_hmac('sha256', 'agroconecta:producer:'.$attribute.':v1:'.$producer->$attribute, $binaryKey);
                        if (! hash_equals($producer->getAttribute($attribute.'_lookup'), $fingerprint)) {
                            throw new RuntimeException('Un índice privado no coincide con su contenido.');
                        }
                    }
                }
                $references = 0;
                foreach ([PaymentProof::class, ReceiptPhoto::class, OfferPhoto::class, ReportExport::class] as $class) {
                    $query = $class::query();
                    if ($class === ReportExport::class) {
                        $query->where('status', 'ready');
                    }
                    foreach ($query->cursor() as $record) {
                        $this->verifyFile($root, $record->path, $record->getAttribute('sha256'), $record->getAttribute('size'));
                        $references++;
                    }
                }
                foreach (Producer::query()->whereNotNull('photo_path')->cursor() as $producer) {
                    $this->verifyFile($root, $producer->photo_path);
                    $references++;
                }
                $files = [];
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink()) {
                        throw new RuntimeException('El respaldo contiene un enlace simbólico inesperado.');
                    }
                    if ($file->isFile()) {
                        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                        $files[$relative] = hash_file('sha256', $file->getPathname());
                    }
                }
                ksort($files);
                $sequences = DB::table('pg_sequences')->where('schemaname', 'public')->orderBy('sequencename')->get(['sequencename', 'last_value', 'increment_by', 'start_value'])->all();

                return [
                    'schema_version' => 1,
                    'tables' => $tables,
                    'database_sha256' => hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR)),
                    'sequences_sha256' => hash('sha256', json_encode($sequences, JSON_THROW_ON_ERROR)),
                    'files_sha256' => hash('sha256', json_encode($files, JSON_THROW_ON_ERROR)),
                    'file_count' => count($files), 'verified_file_references' => $references,
                    'decrypted_fields' => $encryptedFields,
                ];
            });
            $this->line(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('La restauración no pasó la verificación de tablas, claves o archivos. Revisa el respaldo y la configuración original.');

            return self::FAILURE;
        }
    }

    private function verifyFile(string $root, ?string $path, ?string $expectedHash = null, ?int $size = null): void
    {
        $fullPath = $path === null ? false : realpath($root.DIRECTORY_SEPARATOR.$path);
        if ($fullPath === false || ! str_starts_with($fullPath, $root.DIRECTORY_SEPARATOR) || ! is_file($fullPath)) {
            throw new RuntimeException('Falta un archivo privado referenciado.');
        }
        if ($expectedHash !== null && ! hash_equals($expectedHash, hash_file('sha256', $fullPath))) {
            throw new RuntimeException('No coincide la huella de una evidencia privada.');
        }
        if ($size !== null && filesize($fullPath) !== $size) {
            throw new RuntimeException('No coincide el tamaño de una evidencia privada.');
        }
    }
}
