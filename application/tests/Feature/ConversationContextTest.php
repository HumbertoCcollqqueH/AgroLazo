<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Offer;
use App\Models\OfferPriceAgreement;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\Permission;
use App\Models\Producer;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversationContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
    }

    public function test_private_context_is_not_exposed_to_customers_or_disabled_operators(): void
    {
        $participant = User::factory()->withRole('producer')->create();
        foreach (['buyer', 'producer'] as $role) {
            $viewer = User::factory()->withRole($role)->create();
            $this->assertNull(app(ConversationContext::class)->forParticipant($viewer, $participant));
        }
        $disabled = User::factory()->withRole('admin')->create(['active' => false]);
        $this->assertNull(app(ConversationContext::class)->forParticipant($disabled, $participant));
    }

    public function test_coordinator_sees_only_this_producers_offers_and_current_price_conditions(): void
    {
        $actor = User::factory()->withRole('coordinator')->create();
        $participant = User::factory()->withRole('producer')->create(['name' => 'Productor de la conversación']);
        $producer = Producer::factory()->verified()->create(['user_id' => $participant->id]);
        $current = Offer::factory()->create(['producer_id' => $producer->id, 'status' => 'verified', 'version' => 2]);
        OfferPriceAgreement::factory()->create(['offer_id' => $current->id, 'offer_version' => 1, 'version' => 1, 'status' => 'accepted', 'gross_unit_price' => '88.50', 'net_unit_price' => '88.00', 'accepted_at' => now()]);
        OfferPriceAgreement::factory()->create(['offer_id' => $current->id, 'offer_version' => 2, 'version' => 2, 'status' => 'proposed', 'net_unit_price' => '4.50']);
        $cancelled = Offer::factory()->create(['producer_id' => $producer->id, 'status' => 'cancelled']);
        $other = Offer::factory()->create();
        $context = app(ConversationContext::class)->forParticipant($actor, $participant);
        $this->assertSame(2, $context['offer_count']);
        $this->assertEqualsCanonicalizing([$current->id, $cancelled->id], array_column($context['offers'], 'id'));
        $this->assertNotContains($other->id, array_column($context['offers'], 'id'));
        $this->assertNull($context['account_url']);
        $this->assertStringContainsString('4.50', $context['offers'][0]['price']);
        $this->assertStringContainsString('aceptar el precio', $context['offers'][0]['next']);
        $cancelledContext = collect($context['offers'])->firstWhere('id', $cancelled->id);
        $this->assertNull($cancelledContext['price']);
        $this->assertStringContainsString('Cancelada', $cancelledContext['next']);
        $this->assertNotEmpty($context['pending']);
        $this->view('coordination.participant', ['participantContext' => $context])->assertSee('Productor de la conversación')->assertSee('Revisar oferta y precio')->assertDontSee('88.00')->assertDontSee($producer->dni);
    }

    public function test_permissions_gate_producer_prices_and_commerce_sections_individually(): void
    {
        $actor = User::factory()->create();
        $role = Role::query()->create(['code' => 'context-reader', 'name' => 'Lectura de coordinación']);
        $role->permissions()->attach(Permission::where('code', 'view-operations')->sole());
        $actor->roles()->attach($role);
        $participant = User::factory()->withRole('producer')->create();
        Producer::factory()->verified()->create(['user_id' => $participant->id]);
        $context = app(ConversationContext::class)->forParticipant($actor, $participant);
        $this->assertNull($context['producer']);
        $this->assertSame([], $context['offers']);
        $this->assertSame([], $context['orders']);
    }

    public function test_buyer_context_lists_only_their_orders_and_no_forbidden_approval_link(): void
    {
        $actor = User::factory()->withRole('coordinator')->create();
        $participant = User::factory()->withRole('buyer')->create();
        $buyer = Buyer::factory()->create(['user_id' => $participant->id]);
        $quote = Quote::factory()->create(['buyer_id' => $buyer->id]);
        $own = Order::factory()->create(['quote_id' => $quote->id]);
        $other = Order::factory()->create();
        $context = app(ConversationContext::class)->forParticipant($actor, $participant);
        $this->assertSame([$own->code], array_column($context['orders'], 'code'));
        $this->assertNotContains($other->code, array_column($context['orders'], 'code'));
        $this->assertSame('Pendiente de aprobación', $context['buyer']['status']);
        $this->assertSame(1, $context['active_order_count']);
        $this->assertNull($context['account_url']);
        $this->assertNull($context['pending'][0]['url']);
    }

    public function test_producer_orders_are_found_through_their_own_contributions(): void
    {
        $actor = User::factory()->withRole('admin')->create();
        $participant = User::factory()->withRole('producer')->create();
        $allocation = OrderAllocation::factory()->create();
        $producer = $allocation->portion->offer->producer;
        $producer->forceFill(['user_id' => $participant->id])->save();
        $other = Order::factory()->create();
        $context = app(ConversationContext::class)->forParticipant($actor, $participant);
        $this->assertSame([$allocation->orderItem->order->code], array_column($context['orders'], 'code'));
        $this->assertNotContains($other->code, array_column($context['orders'], 'code'));
        $this->assertNotNull($context['account_url']);
        Http::assertNothingSent();
    }
}
