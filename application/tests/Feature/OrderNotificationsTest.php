<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Conversation;
use App\Models\Lot;
use App\Models\LotOffer;
use App\Models\Message;
use App\Models\Offer;
use App\Models\OperationalNotice;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\PricingPolicy;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\CheckoutService;
use App\Services\Messaging\MessageDelivery;
use App\Services\OperationalNotifier;
use App\Services\PricingService;
use App\Services\SettlementService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class OrderNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);
    }

    private function notice(Order $order): OperationalNotice
    {
        return OperationalNotice::query()->where('event_key', 'like', 'order-confirmed:'.$order->id.':producer:%')->sole();
    }

    private function conversation(OperationalNotice $notice, array $extra = []): Conversation
    {
        return Conversation::factory()->create(['producer_id' => $notice->producer_id, 'phone' => $notice->producer->phone, 'phone_lookup' => $notice->producer->phone_lookup, 'provider' => 'fake', 'consent' => 'granted', ...$extra]);
    }

    private function combinedOrder(bool $sameProducer, bool $real = false): Order
    {
        $buyer = User::factory()->withRole('buyer')->create();
        Buyer::factory()->approved()->create(['user_id' => $buyer->id]);
        $first = Offer::factory()->published()->create(['quantity' => '20.000', 'normalized_quantity' => '20.000']);
        $lot = Lot::factory()->withOffer($first, '20.000')->create(['status' => 'published', 'published_at' => now()]);
        $second = Offer::factory()->published()->create([...$first->only(['product_id', 'variety_id', 'quality_grade_id', 'location_id', 'unit_id', 'base_unit_id']), 'quantity' => '40.000', 'normalized_quantity' => '40.000', ...($sameProducer ? ['producer_id' => $first->producer_id] : [])]);
        LotOffer::factory()->create(['lot_id' => $lot->id, 'offer_id' => $second->id, 'agreement_id' => $second->activeAgreement()->id, 'offer_version' => $second->version, 'quantity' => '40.000']);
        $policy = PricingPolicy::factory()->create(['base_unit_id' => $lot->base_unit_id, 'synthetic' => ! $real]);
        $quote = app(PricingService::class)->issue($buyer, $lot, CommerceScenario::key(['quantity' => '60.000', 'policy_id' => $policy->id, 'delivery_address' => CommerceScenario::address(), 'delivery_details' => 'Retiro sintético coordinado en acopio', 'payment_method' => 'coordinated']));

        return app(CheckoutService::class)->confirm($buyer, $quote, CommerceScenario::key(['accepted' => '1', 'requested_delivery_on' => now('America/Lima')->addDays(2)->toDateString()]));
    }

    public function test_checkout_notifies_the_producer_and_coordinators_without_inventing_a_collection_point_or_a_payment(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        $this->assertStringContainsString($scenario->order->code, $notice->body);
        $this->assertStringContainsString($notice->producer->full_name, $notice->body);
        $this->assertStringContainsString($scenario->order->items->sole()->product_snapshot['product'], $notice->body);
        $this->assertStringContainsString($scenario->order->items->sole()->product_snapshot['variety'], $notice->body);
        $this->assertStringContainsString('60 '.$scenario->order->quote->baseUnit->code, $notice->body);
        $this->assertStringContainsString('lugar y horario pendientes', $notice->body);
        $this->assertStringContainsString('Entrega solicitada por el comprador: '.$scenario->order->requested_delivery_on->format('d/m/Y'), $notice->body);
        $this->assertStringContainsString('no se cobra flete al productor', $notice->body);
        $this->assertStringNotContainsString($scenario->point->name, $notice->body);
        $this->assertStringContainsString('no acredita el pago', $notice->body);
        $this->assertDatabaseHas('outbox_events', ['event_key' => 'notice:'.$notice->id, 'type' => 'commerce_notice']);
        $this->assertDatabaseHas('operational_notices', ['order_id' => $scenario->order->id, 'user_id' => $scenario->staff->id, 'event_key' => 'order-confirmed:'.$scenario->order->id.':operator:'.$scenario->staff->id]);
        $buyerNotice = OperationalNotice::query()->where('user_id', $scenario->buyer->id)->sole();
        $this->assertStringContainsString('simula el registro de pago; no transfieras dinero real', $buyerNotice->body);
        Http::assertNothingSent();
    }

    public function test_one_producer_with_two_contributions_gets_one_combined_purchase_notice(): void
    {
        $order = $this->combinedOrder(true);
        $notice = $this->notice($order);
        $this->assertStringContainsString('60 '.$order->quote->baseUnit->code, $notice->body);
        foreach ($order->items->sole()->allocations as $allocation) {
            $this->assertStringContainsString('#'.$allocation->portion->offer_id, $notice->body);
        }
    }

    public function test_each_producer_receives_only_their_quantity_and_their_offer_references(): void
    {
        $order = $this->combinedOrder(false);
        $notices = OperationalNotice::query()->where('order_id', $order->id)->whereNotNull('producer_id')->get();
        $this->assertCount(2, $notices);
        foreach ($notices as $notice) {
            $own = $order->items->sole()->allocations->first(fn ($a) => $a->portion->offer->producer_id === $notice->producer_id);
            $other = $order->items->sole()->allocations->first(fn ($a) => $a->portion->offer->producer_id !== $notice->producer_id);
            $this->assertStringContainsString(bcadd($own->quantity, '0', 0).' '.$order->quote->baseUnit->code, $notice->body);
            $this->assertStringContainsString('#'.$own->portion->offer_id, $notice->body);
            $this->assertStringNotContainsString(bcadd($other->quantity, '0', 0).' '.$order->quote->baseUnit->code, $notice->body);
            $this->assertStringNotContainsString($other->portion->offer->producer->full_name, $notice->body);
        }
    }

    public function test_recovery_and_delivery_are_idempotent_and_preserve_an_active_offer_draft(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        $conversation = $this->conversation($notice, ['state' => 'OFFER_QUANTITY', 'context' => ['draft_id' => 123]]);
        $this->artisan('orders:notify', ['--order' => $scenario->order->id])->assertSuccessful();
        $this->artisan('orders:notify', ['--order' => $scenario->order->id])->assertSuccessful();
        app(OperationalNotifier::class)->deliver($notice->id);
        app(OperationalNotifier::class)->deliver($notice->id);
        $this->assertSame(1, Message::query()->where('deduplication_key', 'commerce-notice:'.$notice->id)->count());
        $this->assertSame('OFFER_QUANTITY', $conversation->fresh()->state);
        $this->assertSame(['draft_id' => 123], $conversation->fresh()->context);
        $this->assertSame(1, OperationalNotice::query()->where('event_key', $notice->event_key)->count());
    }

    public function test_a_synthetic_order_never_sends_to_the_real_whatsapp_conversation(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        $this->conversation($notice, ['provider' => 'evolution']);
        app(OperationalNotifier::class)->deliver($notice->id);
        $this->assertSame('synthetic_simulator_required', $notice->fresh()->error_code);
        $this->assertNull($notice->fresh()->message_id);
        Http::assertNothingSent();
    }

    public function test_explicit_test_mode_sends_a_labelled_whatsapp_once_and_can_be_disabled(): void
    {
        config(['commerce.test_whatsapp_enabled' => true, 'messaging.evolution.url' => 'http://evolution.test', 'messaging.evolution.api_key' => 'test-key']);
        $scenario = new CommerceScenario;
        WhatsAppConnection::query()->create(['id' => 1, 'integration' => 'WHATSAPP-BAILEYS', 'instance_name' => 'test-qr', 'status' => 'connected', 'automation_enabled' => true, 'notice_text' => 'Aviso del canal de prueba', 'notice_version' => 'v1', 'notice_approved' => true, 'revision' => 1, 'updated_by' => $scenario->admin->id]);
        $notice = $this->notice($scenario->order);
        $this->conversation($notice, ['provider' => 'evolution', 'account' => 'qr:test-qr']);
        Http::fake(['*/message/sendText/test-qr' => Http::response(['key' => ['id' => 'TEST-ONLY-001']])]);
        app(OperationalNotifier::class)->deliver($notice->id);
        $message = $notice->fresh()->message;
        $this->assertTrue($message->metadata['test_whatsapp']);
        $this->assertStringContainsString('No prepares ni traslades producto', $message->body);
        app(MessageDelivery::class)->deliver($message->id);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('accepted', $message->fresh()->status);
        Http::assertSentCount(1);
        config(['commerce.test_whatsapp_enabled' => false]);
        $this->assertSame('operational_channel_mismatch', app(MessageDelivery::class)->blockedReason($message->fresh()));
    }

    public function test_unlabelled_presentation_preserves_channel_authorization_and_deduplication(): void
    {
        config(['commerce.show_test_labels' => false, 'commerce.test_whatsapp_enabled' => true, 'messaging.evolution.url' => 'http://evolution.test', 'messaging.evolution.api_key' => 'test-key']);
        $scenario = new CommerceScenario;
        WhatsAppConnection::query()->create(['id' => 1, 'integration' => 'WHATSAPP-BAILEYS', 'instance_name' => 'test-qr', 'status' => 'connected', 'automation_enabled' => true, 'notice_text' => 'Aviso del canal de prueba', 'notice_version' => 'v1', 'notice_approved' => true, 'revision' => 1, 'updated_by' => $scenario->admin->id]);
        $notice = $this->notice($scenario->order);
        $this->conversation($notice, ['provider' => 'evolution', 'account' => 'qr:test-qr']);
        Http::fake(['*/message/sendText/test-qr' => Http::response(['key' => ['id' => 'TEST-ONLY-001']])]);
        app(OperationalNotifier::class)->deliver($notice->id);
        $message = $notice->fresh()->message;
        $this->assertTrue($message->metadata['test_whatsapp']);
        $this->assertStringNotContainsString('PRUEBA', $message->body);
        $this->assertStringContainsString($scenario->order->code, $message->body);
        app(MessageDelivery::class)->deliver($message->id);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('accepted', $message->fresh()->status);
        Http::assertSentCount(1);
        config(['commerce.show_test_labels' => false, 'commerce.test_whatsapp_enabled' => false]);
        $this->assertSame('operational_channel_mismatch', app(MessageDelivery::class)->blockedReason($message->fresh()));
    }

    public function test_a_real_order_requires_current_consented_real_contact_instead_of_falling_back_to_fake(): void
    {
        $scenario = new CommerceScenario;
        DB::table('orders')->where('id', $scenario->order->id)->update(['synthetic' => false]);
        $notice = $this->notice($scenario->order);
        $this->conversation($notice);
        $this->conversation($notice, ['provider' => 'evolution', 'consent' => 'declined']);
        app(OperationalNotifier::class)->deliver($notice->id);
        $this->assertSame('contact_or_consent_required', $notice->fresh()->error_code);
        $this->assertNull($notice->fresh()->message_id);
        Http::assertNothingSent();
    }

    public function test_revoking_consent_after_notice_queueing_blocks_message_delivery(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        $conversation = $this->conversation($notice);
        app(OperationalNotifier::class)->deliver($notice->id);
        $conversation->update(['consent' => 'declined']);
        app(MessageDelivery::class)->deliver($notice->fresh()->message_id);
        $this->assertSame('consent_revoked', $notice->fresh()->message->error_code);
        Http::assertNothingSent();
    }

    public function test_scheduled_collection_includes_actual_product_point_address_and_time(): void
    {
        $scenario = new CommerceScenario;
        $event = $scenario->schedule();
        $notice = OperationalNotice::query()->where('collection_event_id', $event->id)->sole();
        $this->assertStringContainsString($event->point_snapshot['name'], $notice->body);
        $this->assertStringContainsString($event->point_snapshot['address'], $notice->body);
        $this->assertStringContainsString($event->starts_at->setTimezone('America/Lima')->format('d/m/Y H:i'), $notice->body);
        $this->assertStringContainsString($scenario->order->items->sole()->product_snapshot['product'], $notice->body);
        $this->assertStringContainsString('60 '.$scenario->order->quote->baseUnit->code, $notice->body);
        $this->assertStringContainsString('Se verificará el peso y la calidad', $notice->body);
    }

    public function test_receipt_and_approved_settlement_notify_actual_weight_and_no_premature_payment(): void
    {
        $scenario = new CommerceScenario;
        $receipt = $scenario->receive($scenario->schedule());
        $receiptEvent = $scenario->order->events()->where('action', 'receipt_recorded')->sole();
        $notice = OperationalNotice::query()->where('event_key', 'like', 'commerce:'.$receiptEvent->id.':producer:%')->sole();
        $this->assertStringContainsString('aceptado: 60;', $notice->body);
        $this->assertStringContainsString($receipt->event->point_snapshot['name'], $notice->body);
        $settlement = $scenario->settlement();
        $this->assertSame(0, $scenario->order->events()->where('action', 'settlement_approved')->count());
        $scenario->approve($settlement);
        $event = $scenario->order->events()->where('action', 'settlement_approved')->sole();
        $notice = OperationalNotice::query()->where('event_key', 'like', 'commerce:'.$event->id.':producer:%')->sole();
        $this->assertStringContainsString('importe neto: S/ 294.00', $notice->body);
        $this->assertStringContainsString('desembolso aún debe confirmarse', $notice->body);
    }

    public function test_dispatch_delivery_and_payout_emit_separate_truthful_milestones(): void
    {
        $scenario = new CommerceScenario;
        $receipt = $scenario->receive($scenario->schedule());
        $physical = $scenario->consolidate($receipt);
        $scenario->confirm($scenario->payment());
        $scenario->deliver($scenario->ship($physical));
        foreach (['shipment_dispatched', 'shipment_delivered'] as $action) {
            $event = $scenario->order->events()->where('action', $action)->sole();
            $notice = OperationalNotice::query()->where('event_key', 'like', 'commerce:'.$event->id.':producer:%')->sole();
            $this->assertStringContainsString('60 '.$scenario->order->quote->baseUnit->code, $notice->body);
        }
        $settlement = $scenario->approve($scenario->settlement());
        $payout = app(SettlementService::class)->authorize($scenario->admin, $settlement, CommerceScenario::key(['amount' => '294.00', 'reason' => 'Desembolso sintético aprobado luego de verificar el peso']));
        $eventCount = OperationalNotice::query()->where('order_id', $scenario->order->id)->whereNotNull('producer_id')->count();
        $this->assertSame(0, $scenario->order->events()->where('action', 'payout_confirmed')->count());
        app(SettlementService::class)->outcome($scenario->admin, $payout, CommerceScenario::key(['version' => $payout->version, 'result' => 'confirmed', 'operation_reference' => 'SIM-PAYOUT-294', 'notes' => 'Transferencia sintética contrastada contra el comprobante']));
        $event = $scenario->order->events()->where('action', 'payout_confirmed')->sole();
        $notice = OperationalNotice::query()->where('event_key', 'like', 'commerce:'.$event->id.':producer:%')->sole();
        $this->assertStringContainsString('Desembolso confirmado a tu favor: S/ 294.00', $notice->body);
        $this->assertStringContainsString('SIM-PAYOUT-294', $notice->body);
        $this->assertSame($eventCount + 1, OperationalNotice::query()->where('order_id', $scenario->order->id)->whereNotNull('producer_id')->count());
    }

    public function test_failed_checkout_transaction_leaves_no_notices_or_outbox_events(): void
    {
        try {
            DB::transaction(function (): void {
                new CommerceScenario;
                throw new RuntimeException('Rollback para verificar atomicidad');
            });
            $this->fail('La transacción debía revertirse.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback para verificar atomicidad', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('operational_notices', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_recovery_does_not_reopen_a_cancelled_order_and_cancels_undelivered_confirmation(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        $scenario->order->update(['status' => 'cancelled']);
        $this->assertSame(0, app(OperationalNotifier::class)->recoverConfirmedOrders($scenario->order->id));
        app(OperationalNotifier::class)->deliver($notice->id);
        $this->assertSame('cancelled', $notice->fresh()->status);
        $this->assertNull($notice->fresh()->message_id);
    }

    public function test_newly_recovered_notice_uses_current_collection_and_is_created_once(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        DB::table('outbox_events')->where('event_key', 'notice:'.$notice->id)->delete();
        DB::table('operational_notices')->where('id', $notice->id)->delete();
        $event = $scenario->schedule();
        $this->assertSame(1, app(OperationalNotifier::class)->recoverConfirmedOrders($scenario->order->id));
        $this->assertSame(0, app(OperationalNotifier::class)->recoverConfirmedOrders($scenario->order->id));
        $recovered = $this->notice($scenario->order);
        $this->assertStringContainsString($event->point_snapshot['name'], $recovered->body);
        $this->assertStringContainsString($event->point_snapshot['address'], $recovered->body);
        $this->assertStringNotContainsString('lugar y horario pendientes', $recovered->body);
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame('collecting', $scenario->order->fresh()->status);
        $this->assertSame(1, OutboxEvent::query()->where('event_key', 'notice:'.$recovered->id)->count());
    }

    public function test_collection_supersedes_an_unsent_confirmation_even_if_its_message_is_retried(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        $this->conversation($notice);
        app(OperationalNotifier::class)->deliver($notice->id);
        $message = $notice->fresh()->message;
        $scenario->schedule();
        $this->assertSame('cancelled', $notice->fresh()->status);
        $this->assertSame('cancelled', $message->fresh()->status);

        $message->refresh()->update(['status' => 'queued']);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('operational_notice_changed', $message->fresh()->error_code);
        $this->assertSame(0, $message->attempts()->count());
        Http::assertNothingSent();
    }

    public function test_collection_status_is_checked_again_before_sending_a_queued_message(): void
    {
        $scenario = new CommerceScenario;
        $event = $scenario->schedule();
        $notice = OperationalNotice::query()->where('collection_event_id', $event->id)->sole();
        $this->conversation($notice);
        app(OperationalNotifier::class)->deliver($notice->id);
        $event->update(['status' => 'completed']);
        app(MessageDelivery::class)->deliver($notice->fresh()->message_id);
        $this->assertSame('schedule_changed', $notice->fresh()->message->error_code);
        Http::assertNothingSent();
    }

    public function test_synthetic_message_cannot_be_retried_through_a_real_provider(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        $this->conversation($notice);
        app(OperationalNotifier::class)->deliver($notice->id);
        $message = $notice->fresh()->message;
        $message->update(['provider' => 'evolution']);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('operational_channel_mismatch', $message->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_partial_delivery_notice_reports_only_the_quantity_in_that_movement(): void
    {
        $scenario = new CommerceScenario;
        $physical = $scenario->consolidate($scenario->receive($scenario->schedule()));
        $scenario->confirm($scenario->payment());
        $scenario->deliver($scenario->ship($physical), '20.000');
        $event = $scenario->order->events()->where('action', 'shipment_delivered')->sole();
        $notice = OperationalNotice::query()->where('event_key', 'like', 'commerce:'.$event->id.':producer:%')->sole();
        $this->assertStringContainsString('20 '.$scenario->order->quote->baseUnit->code, $notice->body);
        $this->assertStringNotContainsString('60 '.$scenario->order->quote->baseUnit->code, $notice->body);
        $this->assertSame('in_transit', $scenario->order->fresh()->status);
    }

    public function test_operator_access_is_rechecked_before_delivering_private_order_notifications(): void
    {
        $scenario = new CommerceScenario;
        $notice = OperationalNotice::query()->where('user_id', $scenario->staff->id)->sole();
        $scenario->staff->roles()->detach();
        app(OperationalNotifier::class)->deliver($notice->id);
        $this->assertSame('recipient_access_changed', $notice->fresh()->error_code);
        $this->assertDatabaseMissing('notifications', ['id' => $notice->notification_uuid]);
    }

    public function test_only_coordination_can_recover_missing_notices_from_the_order_screen(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        DB::table('outbox_events')->where('event_key', 'notice:'.$notice->id)->delete();
        DB::table('operational_notices')->where('id', $notice->id)->delete();

        $url = route('fulfillment.notices.recover', $scenario->order);
        $this->actingAs($scenario->buyer)->post($url, CommerceScenario::key())->assertForbidden();
        $this->actingAs($scenario->staff)->post($url, CommerceScenario::key())->assertRedirect(route('commerce.orders.show', $scenario->order));
        $this->actingAs($scenario->staff)->post($url, CommerceScenario::key())->assertRedirect();
        $this->assertSame(1, OperationalNotice::query()->where('event_key', $notice->event_key)->count());
        Http::assertNothingSent();
    }

    public function test_a_real_confirmed_purchase_queues_its_notice_for_the_authorized_whatsapp_contact(): void
    {
        config(['commerce.live_enabled' => true]);
        $order = $this->combinedOrder(true, true);
        $notice = $this->notice($order);
        $conversation = $this->conversation($notice, ['provider' => 'evolution']);
        $this->assertFalse($order->synthetic);
        $this->assertStringNotContainsString('PRUEBA', $notice->body);

        app(OperationalNotifier::class)->deliver($notice->id);
        $message = $notice->fresh()->message;
        $this->assertSame('evolution', $message->provider);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame('queued', $message->status);
        $this->assertSame($notice->producer->phone, $message->conversation->phone);
        $this->assertDatabaseHas('outbox_events', ['event_key' => 'deliver:'.$message->id, 'type' => 'deliver']);
        Http::assertNothingSent();
    }

    public function test_simulated_purchase_message_is_delivered_once_without_network_requests(): void
    {
        $scenario = new CommerceScenario;
        $notice = $this->notice($scenario->order);
        $this->conversation($notice);
        app(OperationalNotifier::class)->deliver($notice->id);
        $message = $notice->fresh()->message;
        app(MessageDelivery::class)->deliver($message->id);
        app(MessageDelivery::class)->deliver($message->id);
        $this->assertSame('accepted', $message->fresh()->status);
        $this->assertSame(1, $message->attempts()->count());
        Http::assertNothingSent();
    }
}
