<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\ProducerPayout;
use App\Models\ProducerSettlement;
use App\Models\Role;
use App\Services\PaymentService;
use App\Services\SettlementService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('agroconecta_testing', config('database.connections.pgsql.database'));
    }

    private function scenario(): CommerceScenario
    {
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);

        return new CommerceScenario;
    }

    private function race(array $commands): array
    {
        $code = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->environment() !== "testing" || config("database.connections.pgsql.database") !== "agroconecta_testing") { exit(2); }
config(["producers.lookup_key" => str_repeat("a", 64)]);
\Illuminate\Support\Facades\DB::statement("SET application_name = 'agro-finance-race'");
$c = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
try {
    $result = app($c["service"])->{$c["method"]}(\App\Models\User::findOrFail($c["actor"]), $c["model"]::findOrFail($c["id"]), $c["data"]);
    echo "OK:".$result->id;
} catch (\Illuminate\Validation\ValidationException $exception) {
    echo "REJECTED";
}
PHP;
        $processes = [];
        DB::beginTransaction();
        Role::query()->where('code', 'admin')->lockForUpdate()->firstOrFail();
        try {
            foreach ($commands as $command) {
                $process = new Process([PHP_BINARY, '-r', $code, base64_encode(json_encode($command, JSON_THROW_ON_ERROR))], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'agroconecta_testing', 'DB_URL' => '',
                    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
                ], timeout: 120);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 60;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::scalar("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = 'agro-finance-race' AND wait_event_type = 'Lock'");
                if ($waiting === count($commands)) {
                    break;
                }
                usleep(25000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($commands), $waiting);
            DB::commit();
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $outcomes[] = $process->getOutput();
            }

            return $outcomes;
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

    public function test_concurrent_refunds_reserve_at_most_the_confirmed_incoming_amount(): void
    {
        $s = $this->scenario();
        $payment = $s->confirm($s->payment('100.00'));
        $commands = [];
        for ($i = 0; $i < 2; $i++) {
            $commands[] = ['actor' => $s->admin->id, 'service' => PaymentService::class, 'method' => 'refund', 'model' => Payment::class, 'id' => $payment->id, 'data' => CommerceScenario::key(['amount' => '60.00', 'reason' => 'Devolución sintética concurrente de prueba'])];
        }
        $outcomes = $this->race($commands);
        $this->assertSame(1, count(array_filter($outcomes, fn ($value): bool => str_starts_with($value, 'OK:'))));
        $this->assertContains('REJECTED', $outcomes);
        $this->assertSame(6000, (int) PaymentRefund::query()->whereIn('status', PaymentService::HELD)->sum('amount_minor'));
    }

    public function test_concurrent_payouts_cannot_commit_more_than_the_latest_producer_obligation(): void
    {
        $s = $this->scenario();
        $s->receive($s->schedule());
        $settlement = $s->approve($s->settlement());
        $commands = [];
        for ($i = 0; $i < 2; $i++) {
            $commands[] = ['actor' => $s->admin->id, 'service' => SettlementService::class, 'method' => 'authorize', 'model' => ProducerSettlement::class, 'id' => $settlement->id, 'data' => CommerceScenario::key(['amount' => '200.00', 'reason' => 'Desembolso sintético concurrente de prueba'])];
        }
        $outcomes = $this->race($commands);
        $this->assertSame(1, count(array_filter($outcomes, fn ($value): bool => str_starts_with($value, 'OK:'))));
        $this->assertContains('REJECTED', $outcomes);
        $this->assertSame(20000, (int) ProducerPayout::query()->whereIn('status', PaymentService::HELD)->sum('amount_minor'));
    }
}
