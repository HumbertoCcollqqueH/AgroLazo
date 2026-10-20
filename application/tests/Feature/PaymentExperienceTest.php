<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\PaymentMethodQr;
use App\Services\PaymentService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CommerceScenario;
use Tests\TestCase;

class PaymentExperienceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private CommerceScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        config(['producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->seed([RolesAndPermissionsSeeder::class, PaymentMethodSeeder::class]);
        $this->scenario = new CommerceScenario;
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('qr.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lL8AAAAASUVORK5CYII='));
    }

    private function configuredMethod(): PaymentMethod
    {
        $method = PaymentMethod::query()->where('code', 'yape')->sole();
        $method->update(['recipient' => 'AgroLazo titular 912345678', 'instructions' => 'Verifica el receptor antes de autorizar la transferencia.', 'live_ready' => true]);

        return $method;
    }

    public function test_verified_qr_is_private_versioned_and_upload_retry_is_idempotent(): void
    {
        $method = $this->configuredMethod();
        $data = CommerceScenario::key(['version' => $method->version, 'recipient_verified' => '1']);
        $saved = app(PaymentMethodQr::class)->store($this->scenario->admin, $method, $data, $this->image());
        $this->assertTrue($saved->hasCurrentQr());
        $this->assertSame(2, $saved->version);
        app(PaymentMethodQr::class)->store($this->scenario->admin, $method, $data, $this->image());
        $this->assertCount(1, Storage::disk('local')->allFiles('payment-methods'));
        $url = route('payments.methods.qr', ['method' => $method, 'version' => $saved->version, 'order' => $this->scenario->order->id]);
        $this->actingAs($this->scenario->admin)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->scenario->buyer)->get($url)->assertNotFound();
        DB::table('orders')->where('id', $this->scenario->order->id)->update(['synthetic' => false]);
        $this->get($url)->assertOk();
        $this->actingAs(User::factory()->withRole('buyer')->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->withRole('producer')->create())->get($url)->assertForbidden();
        $this->actingAs($this->scenario->buyer)->get(route('payments.methods.qr', ['method' => $method, 'version' => 1, 'order' => $this->scenario->order->id]))->assertNotFound();
    }

    public function test_changing_recipient_disables_old_qr_and_unverified_or_unsafe_uploads_fail(): void
    {
        $method = $this->configuredMethod();
        $saved = app(PaymentMethodQr::class)->store($this->scenario->admin, $method, CommerceScenario::key(['version' => 1, 'recipient_verified' => 1]), $this->image());
        $method = app(PaymentService::class)->configureMethod($this->scenario->admin, $saved, CommerceScenario::key(['version' => 2, 'recipient' => 'Otro titular 987654321', 'instructions' => 'Cuenta nueva comprobada por administración.', 'active' => true, 'live_ready' => true]));
        $this->assertFalse($method->hasCurrentQr());
        $this->actingAs($this->scenario->admin)->get(route('payments.methods.qr', ['method' => $method, 'version' => 3]))->assertNotFound();
        $route = route('payments.methods.qr.store', $method);
        $this->post($route, CommerceScenario::key(['version' => 3, 'qr' => $this->image()]))->assertSessionHasErrors('recipient_verified');
        $this->post($route, CommerceScenario::key(['version' => 3, 'recipient_verified' => 1, 'qr' => UploadedFile::fake()->createWithContent('qr.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]))->assertSessionHasErrors('qr');
        $this->actingAs($this->scenario->buyer)->post($route, CommerceScenario::key(['version' => 3, 'qr' => $this->image(), 'recipient_verified' => 1]))->assertForbidden();
        $this->assertCount(1, Storage::disk('local')->allFiles('payment-methods'));
    }

    public function test_live_summary_never_credits_a_receipt_and_is_scoped_to_the_order(): void
    {
        $payment = $this->scenario->payment('100.00');
        app(PaymentService::class)->proof($this->scenario->buyer, $payment, CommerceScenario::key(), $this->image());
        $url = route('payments.status', $this->scenario->order);
        $this->actingAs($this->scenario->buyer)->getJson($url)->assertOk()->assertJsonPath('balance', '306.00')->assertJsonPath('received', '0.00')->assertJsonPath('pending_count', 1)->assertJsonPath('payments.0.status', 'proof_uploaded');
        $payment = $this->scenario->confirm($payment->fresh());
        $this->getJson($url)->assertOk()->assertJsonPath('balance', '206.00')->assertJsonPath('received', '100.00')->assertJsonPath('pending_count', 0);
        $this->actingAs(User::factory()->withRole('buyer')->create())->getJson($url)->assertForbidden();
    }

    public function test_inbox_filters_by_buyer_and_provides_one_review_with_private_image_preview(): void
    {
        $payment = $this->scenario->payment('100.00');
        $proof = app(PaymentService::class)->proof($this->scenario->buyer, $payment, CommerceScenario::key(), $this->image());
        $this->actingAs($this->scenario->admin)->get(route('payments.inbox', ['q' => $this->scenario->buyer->email]))->assertOk()->assertSee('Confirmar abono y actualizar saldo')->assertSee($this->scenario->order->code)->assertSee('100.00');
        $this->get(route('payments.inbox', ['q' => 'sin-correspondencia-xxx']))->assertOk()->assertDontSee($this->scenario->order->code)->assertSee('No hay cobros en esta vista');
        $this->get(route('payments.proof.preview', $proof))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->scenario->staff)->get(route('payments.inbox'))->assertForbidden();
        $this->actingAs($this->scenario->buyer)->get(route('payments.inbox'))->assertForbidden();
        $this->actingAs(User::factory()->withRole('buyer')->create())->get(route('payments.proof.preview', $proof))->assertForbidden();
    }

    public function test_order_and_settings_render_new_payment_controls_without_exposing_other_accounts(): void
    {
        $this->scenario->payment('100.00');
        $this->actingAs($this->scenario->buyer)->get(route('commerce.orders.show', $this->scenario->order))->assertOk()->assertSee('data-payment-live', false)->assertSee('Enviar pago para verificar');
        $this->actingAs($this->scenario->admin)->get(route('payments.methods'))->assertOk()->assertSee('Cuenta receptora y QR')->assertSee('Primero guarda la cuenta receptora');
        Http::assertNothingSent();
    }

    public function test_rejected_payment_summary_requests_clarification_instead_of_another_transfer(): void
    {
        $payment = $this->scenario->payment('100.00');
        app(PaymentService::class)->reviewPayment($this->scenario->admin, $payment, CommerceScenario::key(['version' => 1, 'result' => 'rejected', 'notes' => 'El comprobante requiere contrastar la cuenta de destino.']));
        $this->actingAs($this->scenario->buyer)->getJson(route('payments.status', $this->scenario->order))->assertOk()->assertJsonPath('headline', 'Un registro necesita aclaración')->assertSee('No vuelvas a transferir ese monto')->assertJsonPath('received', '0.00');
    }
}
