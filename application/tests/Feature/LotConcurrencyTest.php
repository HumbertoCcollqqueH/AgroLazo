<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\LotAvailability;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LotConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('agroconecta_testing', config('database.connections.pgsql.database'));
    }

    public function test_two_independent_processes_cannot_group_the_same_capacity(): void
    {
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        $actor = User::factory()->withRole('coordinator')->create();
        $product = Product::query()->where('slug', 'quinua')->firstOrFail();
        $offer = Offer::factory()->published()->create([
            'product_id' => $product->id, 'variety_id' => $product->varieties()->value('id'),
            'quality_grade_id' => $product->qualityGrades()->value('id'),
            'location_id' => Location::query()->where('name', 'Puno')->value('id'),
            'base_unit_id' => $product->base_unit_id, 'unit_id' => $product->base_unit_id,
            'quantity' => '80.000', 'normalized_quantity' => '80.000',
        ]);
        $code = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->environment() !== "testing" || config("database.connections.pgsql.database") !== "agroconecta_testing") {
    fwrite(STDERR, "Incorrect test database"); exit(2);
}
config(["producers.lookup_key" => str_repeat("a", 64)]);
\Illuminate\Support\Facades\DB::statement("SET application_name = 'agro-lot-concurrency-test'");
$actor = \App\Models\User::findOrFail((int) $argv[1]);
$data = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
echo "READY\n"; flush();
try {
    app(\App\Services\AggregationService::class)->create($actor, $data);
    echo "CREATED\n";
} catch (\Illuminate\Validation\ValidationException $exception) {
    echo "REJECTED\n";
}
PHP;
        $processes = [];
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'agroconecta_testing',
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
        ];
        DB::beginTransaction();
        Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
        try {
            for ($i = 0; $i < 2; $i++) {
                $payload = [
                    'source_key' => (string) Str::uuid(), 'reason' => 'Agrupación concurrente sintética',
                    'portions' => [['offer_id' => $offer->id, 'quantity' => '60.000', 'offer_version' => 1, 'agreement_id' => $offer->activeAgreement()->id]],
                ];
                $process = new Process([PHP_BINARY, '-r', $code, (string) $actor->id, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], base_path(), $environment, timeout: 30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::scalar("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = 'agro-lot-concurrency-test' AND wait_event_type = 'Lock'");
                if ($waiting === 2) {
                    break;
                }
                usleep(25000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Ambos procesos deben llegar a competir por el bloqueo de inventario.');
            DB::commit();
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $outcomes = array_map(fn (Process $process): string => str_contains($process->getOutput(), 'CREATED') ? 'created' : 'rejected', $processes);
            sort($outcomes);
            $this->assertSame(['created', 'rejected'], $outcomes);
            $this->assertDatabaseCount('lots', 1);
            $this->assertDatabaseCount('lot_offers', 1);
            $this->assertSame('60.000', (string) DB::table('lot_offers')->sum('quantity'));
            $this->assertSame('20.000', app(LotAvailability::class)->ungroupedQuantity($offer));
            $this->assertSame('draft', Lot::query()->sole()->status);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
