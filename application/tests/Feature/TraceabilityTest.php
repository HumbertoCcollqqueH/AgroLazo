<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\OperationalNotice;
use App\Models\TracePublication;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OperationalNotifier;
use App\Services\TraceabilityService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class TraceabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private CommerceScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);
        $this->s = new CommerceScenario;
    }

    private function publication(?int $physicalId = null): TracePublication
    {
        return app(TraceabilityService::class)->publish($this->s->staff, $this->s->order->quote->lot, CommerceScenario::key(['accepted' => '1', 'physical_lot_id' => $physicalId]));
    }

    public function test_public_commercial_trace_is_explicitly_unreceived_and_contains_only_approved_fields(): void
    {
        $publication = $this->publication();
        $response = $this->getJson(route('api.traces.show', $publication->token))->assertOk();
        $this->assertEqualsCanonicalizing(['schema_version', 'kind', 'code', 'product', 'variety', 'quality', 'region', 'unit', 'recorded_at', 'physical_receipt_confirmed', 'synthetic', 'quantity', 'source_count', 'milestones'], array_keys($response->json('data')));
        $response->assertJsonPath('data.kind', 'commercial_lot')->assertJsonPath('data.physical_receipt_confirmed', false)->assertJsonPath('data.quantity', '80.000')->assertJsonPath('data.synthetic', true);
        $producer = $this->s->order->items->sole()->allocations->sole()->portion->offer->producer;
        foreach ([$producer->full_name, $producer->phone, $this->s->buyer->email, $this->s->point->address, 'terms_snapshot', 'agreement_id', 'gross_unit_price', 'token_lookup'] as $private) {
            if (is_string($private) && $private !== '') {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
        $this->get(route('trace.show', $publication->token))->assertOk()->assertSee('Aún no acredita recepción física')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertNotSame($publication->token, DB::table('trace_publications')->value('token'));
    }

    public function test_physical_trace_follows_actual_receipts_and_partial_delivery_without_inventing_full_delivery(): void
    {
        $event = $this->s->schedule();
        $receipt = $this->s->receive($event, '50.000');
        $this->s->receive($event, '10.000');
        $physical = $this->s->consolidate($receipt);
        $shipment = $this->s->ship($physical);
        $this->s->deliver($shipment, '25.125');
        $publication = $this->publication($physical->id);
        $response = $this->getJson(route('trace.show', $publication->token))->assertOk()->assertJsonPath('data.physical_receipt_confirmed', true)->assertJsonPath('data.code', $physical->code)->assertJsonPath('data.quantity', '50.000')->assertJsonPath('data.source_count', 1)->assertJsonPath('data.milestones.1.quantity', '25.125');
        $this->assertCount(2, $response->json('data.milestones'));
        $this->assertStringNotContainsString($this->s->order->code, $response->getContent());
        $this->actingAs($this->s->staff)->get(route('trace.manage', $this->s->order->quote->lot, ['physical_lot_id' => $physical->id]))->assertOk()->assertSee('Publicar ficha y generar QR');
        Http::assertNothingSent();
    }

    public function test_revocation_disables_html_json_and_qr_without_deleting_history(): void
    {
        $publication = $this->publication();
        $this->get(route('trace.qr', $publication->token))->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('<svg', false);
        app(TraceabilityService::class)->revoke($this->s->staff, $publication, CommerceScenario::key(['reason' => 'Retirar la ficha sintética publicada para prueba']));
        $this->get(route('trace.show', $publication->token))->assertNotFound();
        $this->getJson(route('api.traces.show', $publication->token))->assertNotFound();
        $this->get(route('trace.qr', $publication->token))->assertNotFound();
        $this->assertDatabaseCount('trace_publications', 1);
        $this->assertNotNull($publication->fresh()->revoked_at);
        $this->get('/trace/'.$this->s->order->quote->lot->code)->assertNotFound();
    }

    public function test_private_chain_keeps_buyer_isolation_and_staff_can_follow_producer_agreements(): void
    {
        $receipt = $this->s->receive($this->s->schedule());
        $physical = $this->s->consolidate($receipt);
        $this->s->ship($physical);
        $producer = $this->s->order->items->sole()->allocations->sole()->portion->offer->producer;
        $buyer = $this->actingAs($this->s->buyer)->getJson(route('api.orders.trace', $this->s->order))->assertOk();
        $buyer->assertJsonMissingPath('chain.0.producer')->assertJsonMissingPath('chain.0.terms')->assertJsonPath('chain.0.physical_lots.0.code', $physical->code);
        $this->actingAs($this->s->staff)->getJson(route('api.orders.trace', $this->s->order))->assertOk()->assertJsonPath('chain.0.producer', $producer->full_name)->assertJsonPath('chain.0.receipts.0.accepted_quantity', '60.000');
        $this->actingAs($this->s->buyer)->get(route('trace.chain', $this->s->order))->assertOk()->assertSee($physical->code)->assertDontSee($producer->full_name);
        $this->actingAs(User::factory()->withRole('buyer')->create())->getJson(route('api.orders.trace', $this->s->order))->assertForbidden();
    }

    public function test_publication_requires_operator_consent_and_a_physical_lot_from_the_selected_commercial_lot(): void
    {
        $route = route('trace.publish', $this->s->order->quote->lot);
        $this->actingAs($this->s->buyer)->post($route, CommerceScenario::key(['accepted' => '1']))->assertForbidden();
        $this->actingAs($this->s->staff)->postJson($route, CommerceScenario::key(['accepted' => '0']))->assertUnprocessable()->assertJsonValidationErrors('accepted');
        $other = new CommerceScenario;
        $physical = $other->consolidate($other->receive($other->schedule()));
        $this->actingAs($this->s->staff)->postJson($route, CommerceScenario::key(['accepted' => '1', 'physical_lot_id' => $physical->id]))->assertUnprocessable()->assertJsonValidationErrors('physical_lot_id');
        $this->assertDatabaseCount('trace_publications', 0);
    }

    public function test_retries_keep_one_publication_qr_uses_configured_host_and_oversize_url_is_rejected(): void
    {
        $data = CommerceScenario::key(['accepted' => '1']);
        $trace = app(TraceabilityService::class);
        $publication = $trace->publish($this->s->staff, $this->s->order->quote->lot, $data);
        $this->assertTrue($publication->is($trace->publish($this->s->staff, $this->s->order->quote->lot, $data)));
        config(['app.url' => 'https://agroconecta.test']);
        $this->assertSame('https://agroconecta.test/trace/'.$publication->token, $trace->publicUrl($publication->token));
        config(['app.url' => 'https://'.str_repeat('a', 100).'.test']);
        $this->actingAs($this->s->staff)->postJson(route('trace.publish', $this->s->order->quote->lot), CommerceScenario::key(['accepted' => '1']))->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->assertDatabaseCount('trace_publications', 1);
    }

    public function test_public_snapshot_cannot_be_rewritten_after_publication(): void
    {
        $publication = $this->publication();
        $this->expectException(LogicException::class);
        $publication->update(['public_snapshot' => ['producer_phone' => '+51999999999']]);
    }

    public function test_blocked_notice_can_retry_after_contact_fix_without_creating_duplicate_messages(): void
    {
        $event = $this->s->schedule();
        $notice = OperationalNotice::query()->where('collection_event_id', $event->id)->sole();
        $notifier = app(OperationalNotifier::class);
        $notifier->deliver($notice->id);
        $this->assertSame('blocked', $notice->fresh()->status);
        Conversation::factory()->create(['producer_id' => $notice->producer_id, 'phone_lookup' => $notice->producer->phone_lookup, 'provider' => 'fake', 'consent' => 'granted']);
        $data = CommerceScenario::key();
        $this->actingAs($this->s->buyer)->post(route('fulfillment.notices.retry', $notice), $data)->assertForbidden();
        $this->actingAs($this->s->staff)->post(route('fulfillment.notices.retry', $notice), $data)->assertRedirect();
        $notifier->retry($this->s->staff, $notice, $data);
        $notifier->deliver($notice->id);
        $notifier->deliver($notice->id);
        $this->assertSame('queued', $notice->fresh()->status);
        $this->assertSame(1, Message::query()->where('deduplication_key', 'commerce-notice:'.$notice->id)->count());
        Http::assertNothingSent();
    }

    public function test_accepted_quantity_change_cancels_stale_acopio_notice_and_enqueues_updated_individual_quantity(): void
    {
        $event = $this->s->schedule();
        $notice = OperationalNotice::query()->where('collection_event_id', $event->id)->sole();
        $allocation = $this->s->order->items->sole()->allocations->sole();
        $checkout = app(CheckoutService::class);
        $adjustment = $checkout->proposeReduction($this->s->staff, $this->s->order, CommerceScenario::key(['version' => $this->s->order->fresh()->version, 'allocation_id' => $allocation->id, 'quantity' => '10.000', 'retire' => true, 'reason' => 'Ajuste sintético de disponibilidad confirmado']));
        $checkout->acceptReduction($this->s->buyer, $adjustment, CommerceScenario::key(['accepted' => '1']));
        $this->assertSame('cancelled', $notice->fresh()->status);
        $new = OperationalNotice::query()->where('collection_event_id', $event->id)->where('status', 'pending')->sole();
        $this->assertStringContainsString('50 '.$this->s->order->quote->baseUnit->code, $new->body);
        $this->assertStringNotContainsString('60 '.$this->s->order->quote->baseUnit->code, $new->body);
        $this->assertSame('60.000', $event->items->sole()->expected_quantity);
    }
}
