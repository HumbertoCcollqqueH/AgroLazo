<?php

namespace Tests\Feature;

use App\Models\CommerceEvent;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Commerce;
use App\Services\Money;
use App\Services\PaymentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class SimplifiedPaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private CommerceScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);
        $this->scenario = new CommerceScenario;
    }

    private function declaration(array $data = []): array
    {
        return CommerceScenario::key([
            'method_id' => PaymentMethod::query()->where('code', 'yape')->sole()->id,
            'amount' => Money::format($this->scenario->order->total_minor),
            'operation_reference' => 'INGRESO-001',
            'occurred_at' => now('America/Lima')->format('Y-m-d\TH:i'),
            ...$data,
        ]);
    }

    private function proof(string $body = 'COMPROBANTE DE PAGO'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('comprobante.pdf', "%PDF-1.4\n{$body}\n%%EOF");
    }

    private function decision(Payment $payment, array $data = []): array
    {
        return CommerceScenario::key([
            'version' => $payment->version,
            'result' => 'confirmed',
            'funds_verified' => '1',
            'notes' => 'Abono recibido, importe y operación contrastados con la cuenta receptora.',
            ...$data,
        ]);
    }

    private function reject(Payment $payment): Payment
    {
        return app(PaymentService::class)->reviewPayment($this->scenario->admin, $payment, CommerceScenario::key([
            'version' => $payment->version,
            'result' => 'rejected',
            'notes' => 'La imagen está incompleta. Necesitamos ver la referencia del movimiento recibido.',
        ]));
    }

    private function resubmission(Payment $payment): array
    {
        return CommerceScenario::key(['version' => $payment->version, 'notes' => 'Solicito contrastar la misma operación con esta evidencia más clara.']);
    }

    public function test_buyer_can_report_payment_and_private_proof_together_without_crediting_funds(): void
    {
        $data = $this->declaration();
        $this->actingAs($this->scenario->buyer)->post(route('payments.declare', $this->scenario->order), [...$data, 'proof' => $this->proof()])->assertRedirect(route('commerce.orders.show', $this->scenario->order));
        $payment = Payment::query()->sole();
        $this->assertSame('proof_uploaded', $payment->status);
        $this->assertSame(2, $payment->version);
        $this->assertSame(30600, app(PaymentService::class)->balance($this->scenario->order)['buyer_balance_minor']);
        $proof = $payment->proofs()->sole();
        Storage::disk('local')->assertExists($proof->path);
        $this->actingAs(User::factory()->withRole('buyer')->create())->get(route('payments.proof', $proof))->assertForbidden();
        $this->assertSame(1, CommerceEvent::query()->where('action', 'payment_declared')->count());
        $this->assertSame(0, CommerceEvent::query()->where('action', 'payment_confirmed')->count());

        $this->actingAs($this->scenario->buyer)->post(route('payments.declare', $this->scenario->order), [...$data, 'proof' => $this->proof()])->assertRedirect();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_proofs', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('payment-proofs'));
        Http::assertNothingSent();
    }

    public function test_changed_attachment_on_retry_is_rejected_and_reused_proof_rolls_back_second_payment(): void
    {
        $service = app(PaymentService::class);
        $data = $this->declaration();
        $service->declare($this->scenario->buyer, $this->scenario->order, $data, $this->proof());
        $this->actingAs($this->scenario->buyer)->post(route('payments.declare', $this->scenario->order), [...$data, 'proof' => $this->proof('OTRO COMPROBANTE')])->assertSessionHasErrors('key');
        $this->actingAs($this->scenario->buyer)->post(route('payments.declare', $this->scenario->order), [...$this->declaration(['operation_reference' => 'INGRESO-002']), 'proof' => $this->proof()])->assertSessionHasErrors('proof');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_proofs', 1);
        $this->assertDatabaseCount('payment_reviews', 2);
        $this->assertCount(1, Storage::disk('local')->allFiles('payment-proofs'));
    }

    public function test_failure_after_private_upload_rolls_back_payment_and_removes_orphan_file(): void
    {
        $this->partialMock(Commerce::class, function (MockInterface $mock): void {
            $mock->shouldReceive('event')->once()->andThrow(new RuntimeException('Registro interrumpido'));
        });
        try {
            app(PaymentService::class)->declare($this->scenario->buyer, $this->scenario->order, $this->declaration(), $this->proof());
            $this->fail('La operación debe fallar de forma atómica.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Registro interrumpido', $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_proofs', 0);
        $this->assertDatabaseCount('payment_reviews', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('payment-proofs'));
    }

    public function test_admin_confirms_once_with_bank_attestation_and_preserves_audit_and_retry_safety(): void
    {
        $service = app(PaymentService::class);
        $payment = $service->declare($this->scenario->buyer, $this->scenario->order, $this->declaration(), $this->proof());
        $decision = $this->decision($payment);
        $this->actingAs($this->scenario->admin)->post(route('payments.review', $payment), $decision)->assertRedirect();
        $this->assertSame('confirmed', $payment->fresh()->status);
        $this->assertSame(0, $service->balance($this->scenario->order)['buyer_balance_minor']);
        $review = $payment->reviews()->where('action', 'confirmed')->sole();
        $this->assertSame($this->scenario->admin->id, $review->actor_id);
        $this->assertTrue($review->details['funds_verified']);
        $this->assertTrue($review->details['direct_confirmation']);
        $this->assertSame('proof_uploaded', $review->details['before']);
        $this->assertSame(0, $payment->reviews()->where('action', 'under_review')->count());
        $this->actingAs($this->scenario->admin)->post(route('payments.review', $payment), $decision)->assertRedirect();
        $this->assertSame(1, CommerceEvent::query()->where('action', 'payment_confirmed')->count());
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_capture_and_matching_amount_never_bypass_explicit_verification_or_permissions(): void
    {
        $payment = app(PaymentService::class)->declare($this->scenario->buyer, $this->scenario->order, $this->declaration(), $this->proof());
        $missing = $this->decision($payment);
        unset($missing['funds_verified']);
        $this->actingAs($this->scenario->admin)->post(route('payments.review', $payment), $missing)->assertSessionHasErrors('funds_verified');
        $this->actingAs($this->scenario->admin)->post(route('payments.review', $payment), $this->decision($payment, ['funds_verified' => '0']))->assertSessionHasErrors('funds_verified');
        $this->actingAs($this->scenario->staff)->post(route('payments.review', $payment), $this->decision($payment))->assertForbidden();
        $this->actingAs($this->scenario->buyer)->post(route('payments.review', $payment), $this->decision($payment))->assertForbidden();
        $this->assertSame('proof_uploaded', $payment->fresh()->status);
        $this->assertSame(30600, app(PaymentService::class)->balance($this->scenario->order)['buyer_balance_minor']);
    }

    public function test_pending_cash_can_be_confirmed_directly_but_stale_versions_cannot(): void
    {
        $payment = $this->scenario->payment('100.00', ['method_id' => PaymentMethod::query()->where('code', 'cash')->sole()->id]);
        $this->actingAs($this->scenario->admin)->post(route('payments.review', $payment), $this->decision($payment))->assertRedirect();
        $this->actingAs($this->scenario->admin)->post(route('payments.review', $payment), $this->decision($payment))->assertSessionHasErrors('version');
        $this->assertSame(20600, app(PaymentService::class)->balance($this->scenario->order)['buyer_balance_minor']);
    }

    public function test_rejection_does_not_require_an_attestation_of_received_money(): void
    {
        $payment = $this->scenario->payment();
        $data = $this->decision($payment, ['result' => 'rejected', 'notes' => 'El número de operación no coincide con el movimiento de la cuenta receptora.']);
        unset($data['funds_verified']);
        $this->actingAs($this->scenario->admin)->post(route('payments.review', $payment), $data)->assertRedirect();
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame(0, app(PaymentService::class)->balance($this->scenario->order)['received_minor']);
        $this->actingAs($this->scenario->buyer)->get(route('commerce.orders.show', $this->scenario->order))->assertOk()->assertSee('El número de operación no coincide');
    }

    public function test_screen_prefills_remaining_payment_and_removes_the_start_review_step(): void
    {
        $this->actingAs($this->scenario->buyer)->get(route('commerce.orders.show', $this->scenario->order))->assertOk()->assertSee('value="306.00"', false)->assertSee('Registrar pago y comprobante');
        $payment = $this->scenario->payment('100.00');
        $this->actingAs($this->scenario->buyer)->get(route('commerce.orders.show', $this->scenario->order))->assertOk()->assertSee('value="206.00"', false)->assertSee('No repitas ese pago')->assertDontSee('Confirmar abono y actualizar saldo');
        $this->actingAs($this->scenario->admin)->get(route('commerce.orders.show', $this->scenario->order))->assertOk()->assertSee('Confirmar abono y actualizar saldo')->assertDontSee('Iniciar revisión')->assertSee('data-payment-state="'.$payment->id.'"', false);
    }

    public function test_later_proof_upload_notifies_once_without_duplicate_review_events(): void
    {
        $payment = $this->scenario->payment();
        $service = app(PaymentService::class);
        $data = CommerceScenario::key();
        $service->proof($this->scenario->buyer, $payment, $data, $this->proof());
        $service->proof($this->scenario->buyer, $payment, $data, $this->proof());
        $service->proof($this->scenario->buyer, $payment, CommerceScenario::key(), $this->proof());
        $this->assertSame(1, CommerceEvent::query()->where('action', 'payment_proof_uploaded')->count());
        $this->assertDatabaseCount('payment_proofs', 1);
        $this->assertDatabaseCount('payment_reviews', 2);
    }

    public function test_buyer_reopens_rejected_payment_with_original_proof_without_duplicate_money_or_evidence(): void
    {
        $service = app(PaymentService::class);
        $payment = $this->reject($service->declare($this->scenario->buyer, $this->scenario->order, $this->declaration(), $this->proof()));
        $original = $payment->only(['amount_minor', 'operation_reference', 'reference_lookup', 'occurred_at', 'recipient_snapshot', 'payment_method_id', 'order_id']);
        $data = $this->resubmission($payment);
        $this->actingAs($this->scenario->buyer)->get(route('commerce.orders.show', $this->scenario->order))->assertOk()->assertSee('Solicitar nueva revisión de este pago')->assertSee('No vuelvas a transferir ese monto');
        $this->actingAs($this->scenario->buyer)->post(route('payments.reopen', $payment), [...$data, 'proof' => $this->proof()])->assertRedirect();
        $this->assertSame('proof_uploaded', $payment->fresh()->status);
        $this->assertEquals($original, $payment->fresh()->only(array_keys($original)));
        $this->assertNull($payment->fresh()->confirmed_at);
        $this->assertNull($payment->fresh()->reviewed_by);
        $this->assertSame(30600, $service->balance($this->scenario->order)['buyer_balance_minor']);
        $this->actingAs($this->scenario->buyer)->post(route('payments.reopen', $payment), [...$data, 'proof' => $this->proof()])->assertRedirect();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_proofs', 1);
        $this->assertSame(1, $payment->reviews()->where('action', 'resubmitted')->count());
        $this->assertSame(1, $payment->reviews()->where('action', 'rejected')->count());
        $this->assertSame(1, CommerceEvent::query()->where('action', 'payment_resubmitted')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('payment-proofs'));
        $service->reviewPayment($this->scenario->admin, $payment->fresh(), $this->decision($payment->fresh()));
        $this->assertSame(0, $service->balance($this->scenario->order)['buyer_balance_minor']);
    }

    public function test_coordinator_can_resubmit_a_rejection_with_additional_evidence_but_cannot_confirm_it(): void
    {
        $service = app(PaymentService::class);
        $payment = $this->reject($service->declare($this->scenario->buyer, $this->scenario->order, $this->declaration(), $this->proof()));
        $this->actingAs($this->scenario->staff)->post(route('payments.reopen', $payment), [...$this->resubmission($payment), 'proof' => $this->proof('IMAGEN COMPLETA DE LA OPERACION')])->assertRedirect();
        $this->assertSame('proof_uploaded', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_proofs', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles('payment-proofs'));
        $this->actingAs($this->scenario->staff)->post(route('payments.review', $payment), $this->decision($payment->fresh()))->assertForbidden();
        $missing = $this->decision($payment->fresh());
        unset($missing['funds_verified']);
        $this->actingAs($this->scenario->admin)->post(route('payments.review', $payment), $missing)->assertSessionHasErrors('funds_verified');
    }

    public function test_resubmission_rejects_stale_requests_foreign_buyers_and_financial_changes(): void
    {
        $payment = $this->reject($this->scenario->payment());
        $this->actingAs(User::factory()->withRole('buyer')->create())->post(route('payments.reopen', $payment), $this->resubmission($payment))->assertForbidden();
        $this->actingAs($this->scenario->buyer)->post(route('payments.reopen', $payment), [...$this->resubmission($payment), 'version' => $payment->version - 1])->assertSessionHasErrors('version');
        $this->actingAs($this->scenario->buyer)->post(route('payments.reopen', $payment), [...$this->resubmission($payment), 'amount' => '900.00', 'operation_reference' => 'CAMBIADA'])->assertSessionHasErrors(['amount', 'operation_reference']);
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame(30600, $payment->fresh()->amount_minor);
        $this->assertSame(0, CommerceEvent::query()->where('action', 'payment_resubmitted')->count());
    }

    public function test_resubmission_cannot_reopen_confirmed_payment_closed_order_or_reuse_another_payments_proof(): void
    {
        $service = app(PaymentService::class);
        $confirmed = $this->scenario->payment('100.00');
        $confirmed = $service->reviewPayment($this->scenario->admin, $confirmed, $this->decision($confirmed));
        $this->actingAs($this->scenario->buyer)->post(route('payments.reopen', $confirmed), $this->resubmission($confirmed))->assertSessionHasErrors('payment');
        $other = $service->declare($this->scenario->buyer, $this->scenario->order, $this->declaration(['operation_reference' => 'INGRESO-OTRO']), $this->proof());
        $rejected = $this->reject($this->scenario->payment('20.00'));
        $this->actingAs($this->scenario->buyer)->post(route('payments.reopen', $rejected), [...$this->resubmission($rejected), 'proof' => $this->proof()])->assertSessionHasErrors('proof');
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame(0, $rejected->reviews()->where('action', 'resubmitted')->count());
        $this->assertSame(1, $other->proofs()->count());
        DB::table('orders')->where('id', $this->scenario->order->id)->update(['status' => 'closed']);
        $this->actingAs($this->scenario->buyer)->post(route('payments.reopen', $rejected), $this->resubmission($rejected))->assertSessionHasErrors('order');
    }

    public function test_reopen_failure_after_upload_preserves_rejection_and_cleans_new_file(): void
    {
        $payment = $this->reject($this->scenario->payment());
        $this->partialMock(Commerce::class, function (MockInterface $mock): void {
            $mock->shouldReceive('event')->once()->andThrow(new RuntimeException('Reenvío interrumpido'));
        });
        try {
            app(PaymentService::class)->reopen($this->scenario->buyer, $payment, $this->resubmission($payment), $this->proof());
            $this->fail('La reapertura debe revertirse al fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Reenvío interrumpido', $exception->getMessage());
        }
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame($payment->version, $payment->fresh()->version);
        $this->assertDatabaseCount('payment_proofs', 0);
        $this->assertSame(0, $payment->reviews()->where('action', 'resubmitted')->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('payment-proofs'));
    }
}
