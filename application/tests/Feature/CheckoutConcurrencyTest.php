<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PricingPolicy;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\Services\AllocationService;
use App\Services\CheckoutService;
use App\Services\PricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class CheckoutConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('agroconecta_testing', config('database.connections.pgsql.database'));
    }

    private function scenario(): array
    {
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $buyer->id]);
        $offer = Offer::factory()->published()->create();
        $lot = Lot::factory()->withOffer($offer, '80.000')->create(['status' => 'published', 'published_at' => now()]);
        $policy = PricingPolicy::factory()->create(['base_unit_id' => $lot->base_unit_id]);
        $data = ['key' => (string) Str::uuid(), 'quantity' => '60.000', 'policy_id' => $policy->id, 'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Retiro sintético coordinado', 'payment_method' => 'coordinated'];
        $first = app(PricingService::class)->issue($buyer, $lot, $data);
        $second = app(PricingService::class)->issue($buyer, $lot, [...$data, 'key' => (string) Str::uuid()]);

        return [$buyer, $lot, $first, $second];
    }

    /** Both independent PostgreSQL sessions must reach the inventory mutex before it is released. */
    private function race(array $commands, ?callable $beforeRelease = null): array
    {
        $code = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->environment() !== "testing" || config("database.connections.pgsql.database") !== "agroconecta_testing") { exit(2); }
config(["producers.lookup_key" => str_repeat("a", 64)]);
\Illuminate\Support\Facades\DB::statement("SET application_name = 'agro-checkout-race'");
$command = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
try {
    $result = app(\App\Services\CheckoutService::class)->{$command["method"]}(
        \App\Models\User::findOrFail($command["actor"]),
        \App\Models\Quote::findOrFail($command["quote"]),
        $command["data"]
    );
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
                $waiting = (int) DB::scalar("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = 'agro-checkout-race' AND wait_event_type = 'Lock'");
                if ($waiting === count($commands)) {
                    break;
                }
                usleep(25000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($commands), $waiting);
            if ($beforeRelease) {
                $beforeRelease();
            }
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

    public function test_two_reservations_cannot_hold_the_same_sixty_kilograms(): void
    {
        [$buyer, $lot, $first, $second] = $this->scenario();
        $commands = [];
        foreach ([$first, $second] as $quote) {
            $commands[] = ['method' => 'reserve', 'actor' => $buyer->id, 'quote' => $quote->id, 'data' => ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]];
        }
        $outcomes = $this->race($commands);
        $this->assertSame(1, count(array_filter($outcomes, fn ($value): bool => str_starts_with($value, 'OK:'))));
        $this->assertContains('REJECTED', $outcomes);
        $this->assertSame(1, Reservation::query()->where('status', 'active')->count());
        $this->assertSame('20.000', app(AllocationService::class)->free($lot->portions->sole()));
    }

    public function test_concurrent_retries_create_one_order_with_one_set_of_allocations(): void
    {
        [$buyer, $lot, $first] = $this->scenario();
        $command = ['method' => 'confirm', 'actor' => $buyer->id, 'quote' => $first->id, 'data' => ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]];
        $outcomes = $this->race([$command, $command]);
        $this->assertSame($outcomes[0], $outcomes[1]);
        $this->assertStringStartsWith('OK:', $outcomes[0]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_allocations', 1);
        $this->assertSame('20.000', app(AllocationService::class)->free($lot->portions->sole()));
    }

    public function test_expiry_during_lock_wait_prevents_conversion_and_allows_one_reallocation(): void
    {
        [$buyer, $lot, $first, $second] = $this->scenario();
        $reservation = app(CheckoutService::class)->reserve($buyer, $first, ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]);
        $outcomes = $this->race([
            ['method' => 'confirm', 'actor' => $buyer->id, 'quote' => $first->id, 'data' => ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString(), 'reservation_id' => $reservation->id]],
            ['method' => 'confirm', 'actor' => $buyer->id, 'quote' => $second->id, 'data' => ['key' => (string) Str::uuid(), 'accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]],
        ], fn () => DB::table('reservations')->where('id', $reservation->id)->update(['expires_at' => now()->subSecond()]));
        $this->assertSame('REJECTED', $outcomes[0]);
        $this->assertStringStartsWith('OK:', $outcomes[1]);
        $this->assertSame($second->id, Order::query()->sole()->quote_id);
        $this->assertSame('20.000', app(AllocationService::class)->free($lot->portions->sole()));
    }
}
