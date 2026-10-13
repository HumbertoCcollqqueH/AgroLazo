<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\SettlementService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class PaymentSettlementTest extends TestCase
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

    private function key(array $data = []): array
    {
        return CommerceScenario::key($data);
    }

    private function rejected(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Debía rechazar '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function outcome(string $result = 'confirmed', int $version = 1, string $reference = 'SIM-TRANSFER-001'): array
    {
        return $this->key(['version' => $version, 'result' => $result, 'operation_reference' => $reference, 'notes' => 'Resultado sintético contrastado con evidencia de operación']);
    }

    public function test_proof_is_private_and_does_not_credit_the_buyer_until_review_and_confirmation(): void
    {
        Storage::fake('local');
        $payment = $this->s->payment();
        $file = UploadedFile::fake()->createWithContent('prueba.pdf', "%PDF-1.4\nPRUEBA SINTETICA\n%%EOF");
        $proof = app(PaymentService::class)->proof($this->s->buyer, $payment, $this->key(), $file);
        $this->assertSame('proof_uploaded', $payment->fresh()->status);
        $this->assertSame(30600, app(PaymentService::class)->balance($this->s->order)['buyer_balance_minor']);
        $this->assertNotSame($proof->path, DB::table('payment_proofs')->value('path'));
        $this->actingAs($this->s->buyer)->get(route('payments.proof', $proof))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->withRole('buyer')->create())->get(route('payments.proof', $proof))->assertForbidden();
        $payment = $this->s->confirm($payment->fresh());
        $this->assertSame('confirmed', $payment->status);
        $this->assertSame(0, app(PaymentService::class)->balance($this->s->order)['buyer_balance_minor']);
        $this->actingAs($this->s->buyer)->get(route('commerce.orders.show', $this->s->order))->assertOk()->assertDontSee('Autorizar desembolso');
        $this->actingAs($this->s->admin)->get(route('payments.methods'))->assertOk()->assertSee('Yape');
        Http::assertNothingSent();
    }

    public function test_payment_and_proof_retries_are_idempotent_but_changed_payload_or_duplicate_evidence_is_rejected(): void
    {
        Storage::fake('local');
        $extra = $this->key(['operation_reference' => 'DUPLICADO-LOCAL']);
        $payment = $this->s->payment('20.00', $extra);
        $this->assertTrue($payment->is($this->s->payment('20.00', $extra)));
        $this->rejected(fn () => $this->s->payment('21.00', $extra), 'key');
        $this->rejected(fn () => $this->s->payment('20.00', ['operation_reference' => 'duplicado - local']), 'operation_reference');
        $file = UploadedFile::fake()->createWithContent('prueba.pdf', "%PDF-1.4\nSOLO UNA VEZ\n%%EOF");
        $proof = app(PaymentService::class)->proof($this->s->buyer, $payment, $this->key(), $file);
        $this->assertTrue($proof->is(app(PaymentService::class)->proof($this->s->buyer, $payment, $this->key(), $file)));
        $other = $this->s->payment('10.00');
        $this->rejected(fn () => app(PaymentService::class)->proof($this->s->buyer, $other, $this->key(), $file), 'proof');
        $this->assertDatabaseCount('payment_proofs', 1);
    }

    public function test_cash_still_needs_review_and_coordinator_cannot_confirm_or_bypass_a_stale_version(): void
    {
        $payment = $this->s->payment('100.00', ['method_id' => PaymentMethod::query()->where('code', 'cash')->sole()->id]);
        $this->rejected(fn () => app(PaymentService::class)->reviewPayment($this->s->admin, $payment, $this->outcome()), 'result');
        $this->actingAs($this->s->staff)->post(route('payments.review', $payment), $this->outcome())->assertForbidden();
        $payment = app(PaymentService::class)->reviewPayment($this->s->admin, $payment, $this->outcome('under_review'));
        $this->rejected(fn () => app(PaymentService::class)->reviewPayment($this->s->admin, $payment, $this->outcome()), 'version');
        $payment = app(PaymentService::class)->reviewPayment($this->s->admin, $payment, $this->outcome('confirmed', 2));
        $this->assertSame(20600, app(PaymentService::class)->balance($this->s->order)['buyer_balance_minor']);
        $this->assertNotSame('100.00', $payment->getRawOriginal('operation_reference'));
    }

    public function test_real_dispatch_requires_confirmed_funds_or_a_current_deferred_authorization(): void
    {
        DB::table('orders')->where('id', $this->s->order->id)->update(['synthetic' => false]);
        $order = $this->s->order->fresh();
        $this->rejected(fn () => app(PaymentService::class)->assertDispatchAllowed($order), 'payment');
        config(['commerce.live_enabled' => true]);
        $this->rejected(fn () => app(PaymentService::class)->assertDispatchAllowed($order), 'payment');
        $order = app(PaymentService::class)->defer($this->s->admin, $order, $this->key(['version' => $order->version, 'amount' => '306.00', 'due_at' => now('America/Lima')->addDay()->format('Y-m-d\TH:i'), 'reason' => 'Acuerdo sintético de pago diferido aprobado']));
        app(PaymentService::class)->assertDispatchAllowed($order);
        $this->assertSame(0, app(PaymentService::class)->balance($order)['net_received_minor']);
        DB::table('orders')->where('id', $order->id)->update(['deferred_payment_due_at' => now()->subMinute()]);
        $this->rejected(fn () => app(PaymentService::class)->assertDispatchAllowed($order->fresh()), 'payment');
    }

    public function test_real_payment_method_must_be_configured_and_its_receiver_snapshot_is_immutable(): void
    {
        DB::table('orders')->where('id', $this->s->order->id)->update(['synthetic' => false]);
        $this->s->order = $this->s->order->fresh();
        config(['commerce.live_enabled' => true]);
        $this->rejected(fn () => $this->s->payment(), 'method_id');
        $method = PaymentMethod::query()->where('code', 'yape')->sole();
        $data = ['version' => 1, 'recipient' => 'Receptor sintético 001', 'instructions' => 'Validar el receptor antes de cualquier pago real', 'active' => true, 'live_ready' => true];
        $method = app(PaymentService::class)->configureMethod($this->s->admin, $method, $this->key($data));
        $payment = $this->s->payment();
        app(PaymentService::class)->configureMethod($this->s->admin, $method, $this->key([...$data, 'version' => 2, 'recipient' => 'Receptor sintético 002']));
        $this->assertStringContainsString('001', json_encode($payment->fresh()->recipient_snapshot));
        $this->assertStringNotContainsString('002', json_encode($payment->fresh()->recipient_snapshot));
        Http::assertNothingSent();
    }

    public function test_uncertain_refund_holds_the_balance_and_failure_releases_only_that_authorization(): void
    {
        $payment = $this->s->confirm($this->s->payment('100.00'));
        $service = app(PaymentService::class);
        $refund = $service->refund($this->s->admin, $payment, $this->key(['amount' => '60.00', 'reason' => 'Reembolso sintético parcial autorizado']));
        $refund = $service->refundOutcome($this->s->admin, $refund, $this->outcome('uncertain'));
        $this->rejected(fn () => $service->refund($this->s->admin, $payment, $this->key(['amount' => '40.01', 'reason' => 'Intento de exceder el saldo reembolsable'])), 'amount');
        $this->assertSame(6000, $service->balance($this->s->order)['refund_pending_minor']);
        $this->assertSame(10000, $service->balance($this->s->order)['net_received_minor']);
        $service->refundOutcome($this->s->admin, $refund, $this->outcome('failed', 2));
        $replacement = $service->refund($this->s->admin, $payment, $this->key(['amount' => '100.00', 'reason' => 'Devolución sintética de todo el ingreso']));
        $service->refundOutcome($this->s->admin, $replacement, $this->outcome());
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(0, $service->balance($this->s->order)['net_received_minor']);
    }

    public function test_confirmed_refund_references_cannot_be_reused_and_pending_refunds_block_real_dispatch(): void
    {
        $payment = $this->s->confirm($this->s->payment());
        $service = app(PaymentService::class);
        $first = $service->refund($this->s->admin, $payment, $this->key(['amount' => '10.00', 'reason' => 'Primera devolución sintética parcial']));
        $second = $service->refund($this->s->admin, $payment, $this->key(['amount' => '10.00', 'reason' => 'Segunda devolución sintética parcial']));
        DB::table('orders')->where('id', $this->s->order->id)->update(['synthetic' => false]);
        config(['commerce.live_enabled' => true]);
        $this->rejected(fn () => $service->assertDispatchAllowed($this->s->order->fresh()), 'payment');
        $service->refundOutcome($this->s->admin, $first, $this->outcome());
        $this->rejected(fn () => $service->refundOutcome($this->s->admin, $second, $this->outcome()), 'operation_reference');
        $this->assertSame('partially_refunded', $payment->fresh()->status);
    }

    public function test_settlement_rounds_the_accepted_allocation_once_and_distributes_exact_cents(): void
    {
        $this->s = new CommerceScenario('0.003', '5.000000', '0.100000');
        $event = $this->s->schedule();
        for ($i = 0; $i < 3; $i++) {
            $this->s->receive($event, '0.001');
        }
        $settlement = $this->s->settlement();
        $this->assertSame('0.003', $settlement->quantity);
        $this->assertSame(2, $settlement->gross_minor);
        $this->assertSame(0, $settlement->agreed_cost_minor);
        $this->assertSame(2, $settlement->net_minor);
        $this->assertSame(2, $settlement->items->sum('net_minor'));
        $this->assertCount(3, $settlement->items);
        $this->assertTrue($settlement->is($this->s->settlement()));
    }

    public function test_new_settlement_version_carries_prior_payouts_and_rejects_old_version_payments(): void
    {
        $event = $this->s->schedule();
        $this->s->receive($event, '20.000');
        $first = $this->s->approve($this->s->settlement());
        $service = app(SettlementService::class);
        $payout = $service->authorize($this->s->admin, $first, $this->key(['amount' => '98.00', 'reason' => 'Pago sintético de la primera recepción']));
        $service->outcome($this->s->admin, $payout, $this->outcome());
        $this->s->receive($event, '40.000');
        $this->rejected(fn () => $service->authorize($this->s->admin, $first, $this->key(['amount' => '0.01', 'reason' => 'Intento con liquidación que quedó antigua'])), 'settlement');
        $latest = $this->s->approve($this->s->settlement());
        $this->assertSame(2, $latest->version);
        $this->assertSame(29400, $latest->net_minor);
        $this->assertSame(19600, $service->balance($latest)['available_to_authorize_minor']);
        $this->assertSame(9800, $first->fresh()->net_minor);
        $this->rejected(fn () => $service->authorize($this->s->admin, $latest, $this->key(['amount' => '196.01', 'reason' => 'Intento que excede el nuevo saldo total'])), 'amount');
    }

    public function test_uncertain_payout_stays_reserved_until_reconciled_and_retries_do_not_duplicate_it(): void
    {
        $this->s->receive($this->s->schedule());
        $settlement = $this->s->approve($this->s->settlement());
        $service = app(SettlementService::class);
        $data = $this->key(['amount' => '294.00', 'reason' => 'Liquidación sintética completa autorizada']);
        $payout = $service->authorize($this->s->admin, $settlement, $data);
        $this->assertTrue($payout->is($service->authorize($this->s->admin, $settlement, $data)));
        $payout = $service->outcome($this->s->admin, $payout, $this->outcome('uncertain'));
        $this->assertSame(0, $service->balance($settlement)['available_to_authorize_minor']);
        $this->assertSame(29400, $service->balance($settlement)['remaining_minor']);
        $this->rejected(fn () => $service->authorize($this->s->admin, $settlement, $this->key(['amount' => '0.01', 'reason' => 'No debe repetir una transferencia incierta'])), 'amount');
        $service->outcome($this->s->admin, $payout, $this->outcome('confirmed', 2));
        $this->assertSame(0, $service->balance($settlement)['remaining_minor']);
        $this->assertDatabaseCount('producer_payouts', 1);
    }

    public function test_stale_settlement_cannot_be_approved_and_buyer_cannot_view_producer_finances(): void
    {
        $event = $this->s->schedule();
        $this->s->receive($event, '20.000');
        $draft = $this->s->settlement();
        $this->s->receive($event, '40.000');
        $this->rejected(fn () => $this->s->approve($draft), 'settlement');
        $this->actingAs($this->s->buyer)->post(route('payments.settlements.approve', $draft), $this->key(['notes' => 'Intento del comprador de aprobar liquidación']))->assertForbidden();
        $this->actingAs($this->s->buyer)->get(route('commerce.orders.show', $this->s->order))->assertOk()->assertDontSee('Liquidaciones de productores');
        $this->actingAs($this->s->admin)->get(route('commerce.orders.show', $this->s->order))->assertOk()->assertSee('Liquidaciones de productores');
    }

    public function test_order_closes_only_after_delivery_buyer_balance_and_producer_obligations_are_resolved(): void
    {
        $receipt = $this->s->receive($this->s->schedule());
        $this->s->deliver($this->s->ship($this->s->consolidate($receipt)));
        $service = app(SettlementService::class);
        $close = fn () => $service->close($this->s->admin, $this->s->order, $this->key(['version' => $this->s->order->fresh()->version, 'notes' => 'Cierre sintético con saldos y mercancía conciliados']));
        $this->rejected($close, 'payment');
        $this->s->confirm($this->s->payment());
        $this->rejected($close, 'settlement');
        $settlement = $this->s->approve($this->s->settlement());
        $this->rejected($close, 'settlement');
        $payout = $service->authorize($this->s->admin, $settlement, $this->key(['amount' => '294.00', 'reason' => 'Pago sintético de la obligación pendiente']));
        $this->rejected($close, 'settlement');
        $service->outcome($this->s->admin, $payout, $this->outcome());
        $this->assertSame('closed', $close()->status);
        $this->rejected(fn () => $this->s->payment('1.00'), 'order');
        Http::assertNothingSent();
    }

    public function test_cancelled_unreceived_order_requires_full_refund_but_no_invented_producer_payment(): void
    {
        $payment = $this->s->confirm($this->s->payment());
        $allocation = $this->s->order->items->sole()->allocations->sole();
        $adjustment = app(CheckoutService::class)->proposeReduction($this->s->staff, $this->s->order, $this->key(['version' => $this->s->order->version, 'allocation_id' => $allocation->id, 'quantity' => '60.000', 'retire' => true, 'reason' => 'Cancelación sintética por indisponibilidad confirmada']));
        app(CheckoutService::class)->acceptReduction($this->s->buyer, $adjustment, $this->key(['accepted' => '1']));
        $refund = app(PaymentService::class)->refund($this->s->admin, $payment, $this->key(['amount' => '306.00', 'reason' => 'Devolución sintética por cancelación total']));
        app(PaymentService::class)->refundOutcome($this->s->admin, $refund, $this->outcome());
        $closed = app(SettlementService::class)->close($this->s->admin, $this->s->order, $this->key(['version' => $this->s->order->fresh()->version, 'notes' => 'Cancelación sin recepciones y reembolso conciliado']));
        $this->assertSame('closed', $closed->status);
        $this->assertDatabaseCount('producer_settlements', 0);
        $this->assertDatabaseCount('producer_payouts', 0);
    }

    public function test_invalid_amounts_and_inactive_actor_are_rejected_without_financial_rows(): void
    {
        foreach (['0', '-1', 'NaN', '1e3', '1.001', '99999999999'] as $amount) {
            $this->rejected(fn () => $this->s->payment($amount), 'amount');
        }
        DB::table('users')->where('id', $this->s->admin->id)->update(['active' => false]);
        $payment = $this->s->payment();
        $this->actingAs($this->s->admin->fresh())->post(route('payments.review', $payment), $this->outcome('under_review'))->assertRedirect(route('login'));
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_refunds', 0);
    }
}
