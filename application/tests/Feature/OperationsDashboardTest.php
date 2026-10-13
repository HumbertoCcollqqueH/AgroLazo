<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\CollectionEvent;
use App\Models\CommerceEvent;
use App\Models\Conversation;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Producer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\OperationsDashboard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64), 'commerce.live_enabled' => false]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_operational_queues_keep_older_work_and_separate_real_orders_from_test_orders(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $synthetic = Order::factory()->create(['confirmed_at' => now()->subMonths(2)]);
        $real = Order::factory()->create(['synthetic' => false]);
        Order::factory()->create(['status' => 'closed']);
        Payment::factory()->create(['order_id' => $synthetic->id]);
        Payment::factory()->create(['order_id' => $real->id]);
        CollectionEvent::factory()->create(['order_id' => $synthetic->id]);
        CollectionEvent::factory()->create(['order_id' => $real->id]);
        CommerceEvent::factory()->create(['order_id' => $synthetic->id, 'action' => 'order_confirmed']);
        CommerceEvent::factory()->create(['order_id' => $real->id, 'action' => 'order_confirmed']);

        $snapshot = app(OperationsDashboard::class)->snapshot($admin, ['data' => 'synthetic']);
        $this->assertSame(1, $snapshot['counts']['orders_active']);
        $this->assertSame(1, $snapshot['counts']['payments_pending']);
        $this->assertSame(1, $snapshot['counts']['collections_scheduled']);
        $this->assertTrue($snapshot['recentOrders']->contains('id', $synthetic->id));
        $this->assertFalse($snapshot['recentOrders']->contains('id', $real->id));
        $this->assertSame($synthetic->code, $snapshot['recentActivity']->sole()['order_code']);
        $this->assertSame(['label', 'order_code', 'url', 'occurred_at'], array_keys($snapshot['recentActivity']->sole()));

        $realSnapshot = app(OperationsDashboard::class)->snapshot($admin, ['data' => 'real']);
        $this->assertSame($real->id, $realSnapshot['recentOrders']->sole()->id);
        $this->assertSame($real->code, $realSnapshot['recentActivity']->sole()['order_code']);
        $this->assertSame(1, $realSnapshot['counts']['orders_active']);
        $this->assertSame(1, $realSnapshot['counts']['payments_pending']);
        $this->assertSame(1, $realSnapshot['counts']['collections_scheduled']);
    }

    public function test_pending_lists_are_bounded_and_prioritize_oldest_records(): void
    {
        $coordinator = User::factory()->withRole('coordinator')->create();
        $producers = Producer::factory()->count(7)->create();
        $offers = Offer::factory()->count(7)->create();
        Offer::factory()->create(['status' => 'verified']);
        Conversation::factory()->create(['human_assigned' => true]);

        $snapshot = app(OperationsDashboard::class)->snapshot($coordinator, ['data' => 'synthetic']);

        $this->assertSame(7, $snapshot['counts']['producers_pending']);
        $this->assertSame(7, $snapshot['counts']['offers_pending']);
        $this->assertSame(1, $snapshot['counts']['offers_verified']);
        $this->assertSame(1, $snapshot['counts']['conversations_attention']);
        $this->assertCount(5, $snapshot['pendingOffers']);
        $this->assertCount(5, $snapshot['pendingProducers']);
        $this->assertSame($offers->first()->id, $snapshot['pendingOffers']->first()->id);
        $this->assertSame($producers->first()->id, $snapshot['pendingProducers']->first()->id);
        $this->assertArrayNotHasKey('phone', $snapshot['pendingProducers']->first()->getAttributes());
    }

    public function test_coordinators_do_not_receive_administrator_only_configuration_or_account_counts(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $coordinator = User::factory()->withRole('coordinator')->create();
        Buyer::factory()->for(User::factory()->withRole('buyer'))->create();
        Buyer::factory()->for(User::factory()->withRole('buyer')->inactive())->create();
        Buyer::factory()->for(User::factory()->withRole('coordinator'))->create();
        WhatsAppConnection::query()->create([
            'id' => 1, 'integration' => 'WHATSAPP-BAILEYS', 'instance_name' => 'test-dashboard',
            'status' => 'open', 'automation_enabled' => false, 'notice_text' => 'Aviso de prueba',
            'notice_version' => 'test', 'notice_approved' => true, 'revision' => 1, 'updated_by' => $admin->id,
        ]);

        $adminSnapshot = app(OperationsDashboard::class)->snapshot($admin, ['data' => 'synthetic']);
        $coordinatorSnapshot = app(OperationsDashboard::class)->snapshot($coordinator, ['data' => 'synthetic']);

        $this->assertSame(1, $adminSnapshot['counts']['buyers_pending']);
        $this->assertGreaterThan(0, $adminSnapshot['counts']['users_active']);
        $this->assertSame(['status' => 'open', 'automation_enabled' => false], $adminSnapshot['whatsapp']);
        $this->assertSame(0, $coordinatorSnapshot['counts']['buyers_pending']);
        $this->assertSame(0, $coordinatorSnapshot['counts']['users_active']);
        $this->assertNull($coordinatorSnapshot['whatsapp']);
    }

    public function test_buyer_dashboard_does_not_load_operations_data(): void
    {
        $buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $buyer->id]);
        $this->mock(OperationsDashboard::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('snapshot');
        });

        $this->actingAs($buyer)->get('/dashboard')->assertOk()
            ->assertViewHas('operationsDashboard', null)
            ->assertSee('Tus compras, en cifras');
    }
}
