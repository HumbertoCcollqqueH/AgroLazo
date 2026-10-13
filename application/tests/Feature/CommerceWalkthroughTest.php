<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Lot;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Producer;
use App\Models\ProducerSettlement;
use App\Models\TracePublication;
use App\Models\User;
use App\Services\AllocationService;
use App\Services\PaymentService;
use App\Services\SettlementService;
use Database\Seeders\CommerceDemoSeeder;
use Database\Seeders\CommerceWalkthroughSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommerceWalkthroughTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_local_walkthrough_repeats_without_duplicate_stock_payments_or_publications(): void
    {
        config(['producers.lookup_key' => str_repeat('a', 64), 'commerce.live_enabled' => false]);
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->withRole('admin')->create(['email' => 'admin@agroconecta.test']);
        User::factory()->withRole('coordinator')->create(['email' => 'coordinador@agroconecta.test']);
        $buyer = User::factory()->withRole('buyer')->create(['email' => 'comprador@agroconecta.test']);
        Buyer::factory()->approved()->create(['user_id' => $buyer->id]);
        $producer = Producer::factory()->verified()->create(['full_name' => 'Productora Sintética del Simulador']);
        $offer = Offer::factory()->published('5.000000', '0.500000')->create(['producer_id' => $producer->id]);
        $lot = Lot::factory()->withOffer($offer, '60.000')->create(['code' => 'L-CNNSF4LO', 'status' => 'published', 'published_at' => now()]);
        $this->seed(CommerceDemoSeeder::class);
        $this->seed(CommerceWalkthroughSeeder::class);
        $this->seed(CommerceWalkthroughSeeder::class);
        $order = Order::query()->sole();
        $this->assertSame('closed', $order->status);
        $this->assertSame(12814, $order->total_minor);
        $this->assertSame(0, app(PaymentService::class)->balance($order)['buyer_balance_minor']);
        $settlement = ProducerSettlement::query()->sole();
        $this->assertSame(11307, $settlement->net_minor);
        $this->assertSame(0, app(SettlementService::class)->balance($settlement)['remaining_minor']);
        $this->assertSame('34.875', app(AllocationService::class)->free($lot->portions->sole()));
        $this->assertSame('25.125', TracePublication::query()->sole()->public_snapshot['quantity']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('producer_payouts', 1);
        $this->assertDatabaseCount('collection_receipts', 1);
        Http::assertNothingSent();
    }
}
