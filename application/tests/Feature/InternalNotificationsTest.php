<?php

namespace Tests\Feature;

use App\Events\InboxUpdated;
use App\Models\CommerceEvent;
use App\Models\Offer;
use App\Models\OperationalNotice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Producer;
use App\Models\User;
use App\Services\OfferRegistry;
use App\Services\OperationalNotifier;
use App\Services\PaymentService;
use App\Services\ProducerPortal;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InternalNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogSeeder::class]);
        config(['platform.internal_communication' => true, 'producers.lookup_key' => str_repeat('a', 64)]);
        Http::preventStrayRequests();
    }

    public function test_opening_updates_as_a_page_goes_to_the_inbox_and_json_stays_private(): void
    {
        $owner = User::factory()->withRole('buyer')->create();
        $other = User::factory()->withRole('producer')->create();
        ProducerPortal::notify($owner, 'Precio privado del comprador.', '/commerce', 'owner');
        ProducerPortal::notify($other, 'Mensaje de otra cuenta.', '/producer', 'other');
        $this->actingAs($owner)->get(route('coordination.updates'))->assertRedirectToRoute('notifications.index');
        $this->getJson(route('coordination.updates'))->assertOk()->assertJsonPath('account', $owner->id)
            ->assertJsonPath('unread', 1)->assertJsonPath('notices.0.body', 'Precio privado del comprador.')
            ->assertJsonCount(1, 'notices')->assertDontSee('Mensaje de otra cuenta.');
        $this->get(route('notifications.index'))->assertOk()->assertSee('Activar notificaciones');
    }

    public function test_read_all_and_open_are_scoped_and_old_domains_do_not_escape_the_app(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        ProducerPortal::notify($owner, 'Tu oferta.', 'https://old.example/producer/offers/10?tab=price', 'one');
        ProducerPortal::notify($other, 'Otro aviso.', '/producer', 'two');
        $notice = $owner->notifications()->firstOrFail();
        $this->actingAs($other)->post(route('notifications.open', $notice->id))->assertNotFound();
        $this->actingAs($owner)->post(route('notifications.open', $notice->id))->assertRedirect('/producer/offers/10?tab=price');
        $this->assertNotNull($notice->fresh()->read_at);
        ProducerPortal::notify($owner, 'Otro propio.', '/coordination', 'three');
        $this->post(route('notifications.read-all'))->assertRedirectToRoute('notifications.index');
        $this->assertSame(0, $owner->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_disabled_accounts_cannot_read_background_updates(): void
    {
        $user = User::factory()->create(['active' => false]);
        $this->actingAs($user)->getJson(route('coordination.updates'))->assertForbidden();
    }

    public function test_new_notices_broadcast_only_an_identifier_once(): void
    {
        Event::fake([InboxUpdated::class]);
        $owner = User::factory()->create();
        ProducerPortal::notify($owner, 'Condiciones privadas.', '/producer', 'unique');
        ProducerPortal::notify($owner, 'Condiciones privadas.', '/producer', 'unique');
        Event::assertDispatchedTimes(InboxUpdated::class, 1);
        Event::assertDispatched(InboxUpdated::class, function (InboxUpdated $event) use ($owner): bool {
            $this->assertSame(['revision' => $owner->notifications()->sole()->id], $event->broadcastWith());
            $this->assertSame('private-coordination.user.'.$owner->id, (string) $event->broadcastOn()[0]);

            return true;
        });
    }

    public function test_operator_purchase_notices_are_idempotent_and_stay_inside_the_system(): void
    {
        $operator = User::factory()->withRole('coordinator')->create();
        $event = CommerceEvent::factory()->create(['action' => 'order_confirmed']);
        $notifier = app(OperationalNotifier::class);
        $notifier->producers($event);
        $notifier->coordinators($event);
        $notifier->producers($event);
        $notifier->coordinators($event);
        $notice = OperationalNotice::where('user_id', $operator->id)->sole();
        $notifier->deliver($notice->id);
        $notifier->deliver($notice->id);
        $this->assertSame(1, $operator->notifications()->count());
        $this->assertStringContainsString('confirmada', $operator->notifications()->first()->data['body']);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_expired_offer_informs_its_owner_only_once_without_cancelling_old_sales(): void
    {
        $owner = User::factory()->withRole('producer')->create();
        $producer = Producer::factory()->create(['user_id' => $owner->id]);
        $offer = Offer::factory()->create(['producer_id' => $producer->id, 'available_from' => now('America/Lima')->subDays(3)->toDateString(), 'available_until' => now('America/Lima')->subDay()->toDateString()]);
        app(OfferRegistry::class)->expire();
        app(OfferRegistry::class)->expire();
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertStringContainsString('ventas ya confirmadas', $owner->notifications()->first()->data['body']);
        Http::assertNothingSent();
    }

    public function test_team_notifications_respect_the_permission_for_the_link(): void
    {
        $admin = User::factory()->withRole('admin')->create();
        $coordinator = User::factory()->withRole('coordinator')->create();
        ProducerPortal::team('Comprador pendiente.', '/admin/users', 'new-buyer', 'manage-users');
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $coordinator->notifications()->count());
    }

    public function test_receipt_notice_keeps_payment_pending_and_broadcasts_each_private_inbox_once(): void
    {
        config(['commerce.show_test_labels' => false]);
        Event::fake([InboxUpdated::class]);
        $admin = User::factory()->withRole('admin')->create();
        $order = Order::factory()->create(['original_total_minor' => 10000, 'total_minor' => 10000]);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'amount_minor' => 10000, 'status' => 'proof_uploaded']);
        $event = CommerceEvent::factory()->create(['order_id' => $order->id, 'action' => 'payment_proof_uploaded', 'details' => ['payment_id' => $payment->id]]);
        $notifier = app(OperationalNotifier::class);
        $notifier->buyer($event);
        $notifier->coordinators($event);
        $notifier->buyer($event);
        $notifier->coordinators($event);
        $notices = OperationalNotice::query()->where('order_id', $order->id)->get();
        $this->assertCount(2, $notices);
        foreach ($notices as $notice) {
            $notifier->deliver($notice->id);
            $notifier->deliver($notice->id);
            Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $update): bool => $update->broadcastWith() === ['revision' => $notice->notification_uuid]
                && (string) $update->broadcastOn()[0] === 'private-coordination.user.'.$notice->user_id);
        }
        Event::assertDispatchedTimes(InboxUpdated::class, 2);
        $this->assertStringContainsString('la imagen por sí sola no acredita dinero', $order->buyer->user->notifications()->sole()->data['body']);
        $this->assertStringContainsString('ingreso real en la cuenta receptora', $admin->notifications()->sole()->data['body']);
        $this->assertSame(10000, app(PaymentService::class)->balance($order)['buyer_balance_minor']);
        $this->assertSame('proof_uploaded', $payment->fresh()->status);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_confirmed_payment_notices_distinguish_partial_and_complete_amounts(): void
    {
        config(['commerce.show_test_labels' => false]);
        $admin = User::factory()->withRole('admin')->create();
        $order = Order::factory()->create(['original_total_minor' => 10000, 'total_minor' => 10000]);
        $notifier = app(OperationalNotifier::class);
        $first = Payment::factory()->create(['order_id' => $order->id, 'amount_minor' => 2500, 'status' => 'confirmed']);
        Payment::factory()->create(['order_id' => $order->id, 'amount_minor' => 7500, 'status' => 'proof_uploaded']);
        $partial = CommerceEvent::factory()->create(['order_id' => $order->id, 'action' => 'payment_confirmed', 'details' => ['payment_id' => $first->id]]);
        $notifier->buyer($partial);
        $notifier->coordinators($partial);
        $partialNotice = OperationalNotice::query()->where('event_key', 'commerce:'.$partial->id.':buyer')->sole();
        $this->assertStringContainsString('Ingreso verificado: S/ 25.00. Pago parcial confirmado. Saldo pendiente: S/ 75.00.', $partialNotice->body);

        $second = Payment::factory()->create(['order_id' => $order->id, 'amount_minor' => 7500, 'status' => 'confirmed']);
        $complete = CommerceEvent::factory()->create(['order_id' => $order->id, 'action' => 'payment_confirmed', 'details' => ['payment_id' => $second->id]]);
        $notifier->buyer($complete);
        $notifier->coordinators($complete);
        $completeNotice = OperationalNotice::query()->where('event_key', 'commerce:'.$complete->id.':buyer')->sole();
        $operatorNotice = OperationalNotice::query()->where('event_key', 'commerce:'.$complete->id.':operator:'.$admin->id)->sole();
        $this->assertStringContainsString('Ingreso verificado: S/ 75.00. Pago completo confirmado. Saldo pendiente: S/ 0.00.', $completeNotice->body);
        $this->assertStringContainsString('antes de registrar un despacho', $operatorNotice->body);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_payment_notice_reports_credit_without_promising_an_automatic_refund(): void
    {
        config(['commerce.show_test_labels' => false]);
        $order = Order::factory()->create(['original_total_minor' => 10000, 'total_minor' => 10000]);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'amount_minor' => 12500, 'status' => 'confirmed']);
        $event = CommerceEvent::factory()->create(['order_id' => $order->id, 'action' => 'payment_confirmed', 'details' => ['payment_id' => $payment->id]]);
        app(OperationalNotifier::class)->buyer($event);
        $notice = OperationalNotice::query()->where('event_key', 'commerce:'.$event->id.':buyer')->sole();
        $this->assertStringContainsString('Saldo a favor del comprador: S/ 25.00; administración coordinará su conciliación.', $notice->body);
        $this->assertDatabaseCount('payment_refunds', 0);
    }

    public function test_rejected_payment_notice_asks_to_clarify_before_repeating_a_transfer(): void
    {
        config(['commerce.show_test_labels' => false]);
        $order = Order::factory()->create();
        $payment = Payment::factory()->create(['order_id' => $order->id, 'status' => 'rejected']);
        $event = CommerceEvent::factory()->create(['order_id' => $order->id, 'action' => 'payment_rejected', 'details' => ['payment_id' => $payment->id]]);
        app(OperationalNotifier::class)->buyer($event);
        $notice = OperationalNotice::query()->where('event_key', 'commerce:'.$event->id.':buyer')->sole();
        $this->assertStringContainsString('no se acreditó al saldo', $notice->body);
        $this->assertStringContainsString('antes de repetir una transferencia', $notice->body);
    }

    public function test_payment_clarification_notifies_buyer_and_admin_without_exposing_notes_or_crediting_money(): void
    {
        config(['commerce.show_test_labels' => false]);
        $admin = User::factory()->withRole('admin')->create();
        $order = Order::factory()->create(['original_total_minor' => 10000, 'total_minor' => 10000]);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'amount_minor' => 10000, 'status' => 'pending']);
        $event = CommerceEvent::factory()->create(['order_id' => $order->id, 'action' => 'payment_resubmitted', 'details' => ['payment_id' => $payment->id, 'notes' => 'Información bancaria privada de la aclaración']]);
        $notifier = app(OperationalNotifier::class);
        $notifier->buyer($event);
        $notifier->coordinators($event);
        $buyerNotice = OperationalNotice::query()->where('event_key', 'commerce:'.$event->id.':buyer')->sole();
        $adminNotice = OperationalNotice::query()->where('event_key', 'commerce:'.$event->id.':operator:'.$admin->id)->sole();
        $this->assertStringContainsString('Aclaración de pago recibida', $buyerNotice->body);
        $this->assertStringContainsString('no repitas esta transferencia', $buyerNotice->body);
        $this->assertStringContainsString('El mismo registro vuelve a revisión', $adminNotice->body);
        $this->assertStringNotContainsString('Información bancaria privada', $buyerNotice->body.$adminNotice->body);
        $this->assertSame(10000, app(PaymentService::class)->balance($order)['buyer_balance_minor']);
    }
}
