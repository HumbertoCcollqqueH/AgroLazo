<?php

namespace App\Services;

use App\Events\InboxUpdated;
use App\Models\AuditLog;
use App\Models\CollectionEvent;
use App\Models\CollectionReceipt;
use App\Models\CommerceEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\OperationalNotice;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderAllocation;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\ProducerPayout;
use App\Models\ProducerSettlement;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Notifications\CommerceNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OperationalNotifier
{
    public static function usesRealChannel(Order $order): bool
    {
        return ! $order->synthetic || self::testWhatsAppEnabled();
    }

    public static function testWhatsAppEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && (bool) config('commerce.test_whatsapp_enabled');
    }

    /** Notices follow recorded events; they never approve payments or invent a collection point. */
    public function producers(CommerceEvent $event): void
    {
        $order = $event->order;
        if (! $order) {
            return;
        }
        if ($event->action === 'order_confirmed') {
            $this->orderConfirmed($order);

            return;
        }
        if ($event->action === 'receipt_recorded') {
            $receipt = CollectionReceipt::query()->whereHas('event', fn ($q) => $q->where('order_id', $order->id))->findOrFail($event->details['receipt_id']);
            $allocation = $receipt->allocation;
            $body = 'Recepción registrada. '.$this->allocationName($allocation).'. Recibido: '.$this->quantity($receipt->received_quantity).'; aceptado: '.$this->quantity($receipt->accepted_quantity).'; rechazado: '.$this->quantity($receipt->rejected_quantity).' '.$allocation->orderItem->baseUnit->code.'. Punto: '.$receipt->event->point_snapshot['name'].'. La liquidación se calculará con el peso aceptado y tus condiciones acordadas. Si hay una diferencia, escribe AYUDA.';
            $this->producerEvent($event, $allocation->portion->offer->producer_id, $body);

            return;
        }
        if ($event->action === 'adjustment_accepted') {
            $adjustment = OrderAdjustment::query()->where('order_id', $order->id)->findOrFail($event->details['adjustment_id']);
            $allocations = $adjustment->items()->with('allocation.portion.offer', 'allocation.orderItem.baseUnit')->get()->pluck('allocation');
            foreach ($allocations->groupBy(fn ($a) => $a->portion->offer->producer_id) as $producerId => $items) {
                $this->cancelPending($order, 'order-confirmed:'.$order->id.':producer:'.$producerId, 'order_changed');
                $remaining = $this->producerAllocations($order)->get($producerId, collect());
                $body = $order->status === 'cancelled' ? 'Compra cancelada después del ajuste aceptado. Ya no tienes cantidad comprometida en esta orden.' : 'El comprador aceptó un ajuste. Tu cantidad pendiente comprometida queda así: '.$this->allocationLines($remaining).'.';
                $this->producerEvent($event, (int) $producerId, $body.' Consulta con coordinación si ya entregaste mercancía. Los pagos se concilian por separado.');
            }

            return;
        }
        if ($event->action === 'shipment_dispatched') {
            $shipment = Shipment::query()->where('order_id', $order->id)->findOrFail($event->details['shipment_id']);
            $items = $shipment->items()->with('physicalItem.receipt.allocation.portion.offer', 'physicalItem.receipt.allocation.orderItem.baseUnit')->get();
            foreach ($items->groupBy(fn ($item) => $item->physicalItem->receipt->allocation->portion->offer->producer_id) as $producerId => $rows) {
                $quantity = $rows->reduce(fn (string $sum, $item): string => bcadd($sum, $item->quantity, 3), '0.000');
                $allocation = $rows->first()->physicalItem->receipt->allocation;
                $this->producerEvent($event, (int) $producerId, 'Despacho registrado: '.$this->quantity($quantity).' '.$allocation->orderItem->baseUnit->code.' de tu '.$this->allocationName($allocation).' salieron del acopio hacia el comprador. Te avisaremos cuando se registre la entrega. Este aviso no confirma el pago.');
            }

            return;
        }
        if (in_array($event->action, ['shipment_delivered', 'shipment_returned', 'shipment_lost'], true)) {
            $item = ShipmentItem::query()->whereHas('shipment', fn ($q) => $q->where('order_id', $order->id))->findOrFail($event->details['shipment_item_id']);
            $allocation = $item->physicalItem->receipt->allocation;
            $status = match ($event->action) {
                'shipment_delivered' => 'Entrega al comprador registrada',
                'shipment_returned' => 'Devolución registrada; coordinación revisará la mercancía y el ajuste correspondiente',
                default => 'Incidencia de pérdida registrada; coordinación revisará la cantidad y el ajuste correspondiente',
            };
            $this->producerEvent($event, $allocation->portion->offer->producer_id, $status.': '.$this->quantity($event->details['quantity']).' '.$allocation->orderItem->baseUnit->code.' de '.$this->allocationName($allocation).'. El desembolso se confirma en un aviso separado.');

            return;
        }
        if ($event->action === 'settlement_approved') {
            $settlement = ProducerSettlement::query()->where('order_id', $order->id)->findOrFail($event->details['settlement_id']);
            $body = 'Liquidación aprobada por tu entrega: '.$this->quantity($settlement->quantity).' '.$order->quote->baseUnit->code.'. Importe bruto: S/ '.Money::format($settlement->gross_minor).'; costos acordados: S/ '.Money::format($settlement->agreed_cost_minor).'; importe neto: S/ '.Money::format($settlement->net_minor).'. El desembolso aún debe confirmarse; te avisaremos cuando se registre.';
            $this->producerEvent($event, $settlement->producer_id, $body);

            return;
        }
        if (in_array($event->action, ['payout_confirmed', 'payout_uncertain', 'payout_failed'], true)) {
            $payout = ProducerPayout::query()->where('order_id', $order->id)->findOrFail($event->details['payout_id']);
            $body = $event->action === 'payout_confirmed'
                ? 'Desembolso confirmado a tu favor: S/ '.Money::format($payout->amount_minor).'. Referencia: '.$payout->operation_reference.'. Fecha: '.$payout->confirmed_at->setTimezone('America/Lima')->format('d/m/Y H:i').' (Perú). Comprueba la recepción; si encuentras una diferencia, escribe AYUDA.'
                : 'Tu desembolso de S/ '.Money::format($payout->amount_minor).' sigue pendiente de confirmación. Coordinación está revisando el resultado; este aviso no acredita un pago recibido.';
            $this->producerEvent($event, $payout->producer_id, $body);

            return;
        }
        if ($event->action === 'order_closed') {
            foreach ($this->producerAllocations($order) as $producerId => $items) {
                $this->producerEvent($event, (int) $producerId, 'La operación quedó cerrada y conciliada. Conserva el número de orden para cualquier consulta. Escribe VENDER si deseas registrar una nueva oferta.');
            }
        }
    }

    public function recoverConfirmedOrders(?int $orderId = null): int
    {
        return app(Commerce::class)->transaction(null, null, function () use ($orderId): int {
            $count = 0;
            foreach (Order::query()->whereIn('status', ['confirmed', 'collecting'])->when($orderId !== null, fn ($q) => $q->whereKey($orderId))->orderBy('id')->get() as $order) {
                $count += $this->orderConfirmed($order);
            }

            return $count;
        });
    }

    private function orderConfirmed(Order $order): int
    {
        $event = CollectionEvent::query()->where('order_id', $order->id)->where('status', 'scheduled')->latest('id')->first();
        $collection = $event ? $this->collectionDetails($event) : 'Recojo y acopio: lugar y horario pendientes de programación. Nuestro equipo coordinará el transporte y te enviará el punto, la dirección y la fecha exacta. Espera ese aviso antes de preparar el traslado.';
        $collection = $this->deliveryRequest($order, $event !== null).' '.$collection;
        $count = 0;
        foreach ($this->producerAllocations($order) as $producerId => $allocations) {
            if ($allocations->every(fn ($allocation) => bccomp($allocation->quantity, $allocation->released_quantity, 3) === 0)) {
                continue;
            }
            $body = $this->prefix($order).'Hola, '.$allocations->first()->portion->offer->producer->full_name.'. Compra confirmada. Orden '.$order->code.".\n".$this->allocationLines($allocations)."\n".$collection.' La compra confirmada no acredita el pago; te avisaremos de la recepción y del desembolso. Para apoyo, escribe AYUDA.';
            $notice = $this->enqueue(['event_key' => 'order-confirmed:'.$order->id.':producer:'.$producerId, 'order_id' => $order->id, 'producer_id' => $producerId, 'body' => $body]);
            $count += (int) $notice->wasRecentlyCreated;
        }
        foreach (User::query()->where('active', true)->whereHas('roles.permissions', fn ($q) => $q->where('code', 'manage-commerce'))->get() as $operator) {
            $body = $this->prefix($order).'Compra '.$order->code.' confirmada. '.$this->deliveryRequest($order, $event !== null).' '.($event ? $this->collectionDetails($event) : 'Siguiente paso: coordinar y programar el punto y horario de acopio con los productores.').' Revisa también el pago del comprador antes del despacho.';
            $this->enqueue(['event_key' => 'order-confirmed:'.$order->id.':operator:'.$operator->id, 'order_id' => $order->id, 'user_id' => $operator->id, 'body' => $body]);
        }

        return $count;
    }

    private function producerEvent(CommerceEvent $event, int $producerId, string $body): void
    {
        $this->enqueue(['event_key' => 'commerce:'.$event->id.':producer:'.$producerId, 'order_id' => $event->order_id, 'producer_id' => $producerId, 'body' => $this->prefix($event->order).'Orden '.$event->order->code.'. '.$body]);
    }

    private function producerAllocations(Order $order): Collection
    {
        return OrderAllocation::query()->whereIn('order_item_id', $order->items()->select('id'))->with('portion.offer.producer', 'orderItem.baseUnit')->orderBy('id')->get()->groupBy(fn ($allocation) => $allocation->portion->offer->producer_id);
    }

    private function allocationName(OrderAllocation $allocation): string
    {
        $snapshot = $allocation->orderItem->product_snapshot;

        return $snapshot['product'].', variedad '.$snapshot['variety'];
    }

    private function allocationLines(Collection $allocations): string
    {
        return $allocations->groupBy(fn ($allocation) => $allocation->order_item_id)->map(function (Collection $items): string {
            $quantity = $items->reduce(fn (string $sum, $a): string => bcadd($sum, bcsub($a->quantity, $a->released_quantity, 3), 3), '0.000');
            $first = $items->first();
            $offers = $items->map(fn ($a) => '#'.$a->portion->offer_id)->unique()->implode(', ');

            return $this->allocationName($first).': '.$this->quantity($quantity).' '.$first->orderItem->baseUnit->code.' (ofertas '.$offers.').';
        })->implode("\n");
    }

    private function quantity(string $quantity): string
    {
        return rtrim(rtrim(bcadd($quantity, '0', 3), '0'), '.') ?: '0';
    }

    private function prefix(Order $order): string
    {
        return $order->synthetic && config('commerce.show_test_labels') ? "[PRUEBA SINTÉTICA] No es una venta real. No prepares ni traslades producto; no realices pagos. Los siguientes datos simulan la operación.\n" : '';
    }

    private function collectionDetails(CollectionEvent $event): string
    {
        return 'Punto de acopio: '.$event->point_snapshot['name'].'. Dirección: '.$event->point_snapshot['address'].'. Fecha: '.$event->starts_at->setTimezone('America/Lima')->format('d/m/Y H:i').'–'.$event->ends_at->setTimezone('America/Lima')->format('H:i').' (hora de Perú).';
    }

    private function deliveryRequest(Order $order, bool $scheduled = false): string
    {
        return ($order->requested_delivery_on ? 'Entrega solicitada por el comprador: '.$order->requested_delivery_on->format('d/m/Y').'. ' : '')
            .'El transporte lo coordina y cubre '.config('app.name').'; no se cobra flete al productor.'.($scheduled ? '' : ' El horario de recojo se confirma por separado.');
    }

    private function cancelPending(Order $order, string $eventKey, string $reason): void
    {
        foreach (OperationalNotice::query()->where('order_id', $order->id)->where('event_key', $eventKey)->whereIn('status', ['pending', 'blocked', 'queued'])->get() as $notice) {
            if ($notice->message_id) {
                Message::query()->whereKey($notice->message_id)->whereIn('status', ['queued', 'blocked'])->update(['status' => 'cancelled', 'error_code' => $reason]);
            }
            $notice->update(['status' => 'cancelled', 'error_code' => $reason]);
        }
    }

    public function collection(CollectionEvent $event): void
    {
        $event = $event->fresh();
        $groups = $event->items()->with('allocation.portion.offer.producer')->get()->groupBy(fn ($item) => $item->allocation->portion->offer->producer_id);
        foreach ($groups as $producerId => $items) {
            $this->cancelPending($event->order, 'order-confirmed:'.$event->order_id.':producer:'.$producerId, 'schedule_changed');
            $body = $this->prefix($event->order).'Acopio programado. Orden '.$event->order->code.".\n".$this->allocationLines($items->pluck('allocation'))."\n".$this->collectionDetails($event).' Ten tu producto listo para el recojo coordinado por nuestro equipo. '.$this->deliveryRequest($event->order, true).' Se verificará el peso y la calidad. Si necesitas cambiar la entrega, escribe AYUDA.';
            foreach ($items as $entry) {
                if ($entry->pickup_at && $entry->pickup_address) {
                    $body .= "\nPasaremos a recoger tu oferta #".$entry->allocation->portion->offer_id.' el '.$entry->pickup_at->setTimezone('America/Lima')->format('d/m/Y').' a las '.$entry->pickup_at->setTimezone('America/Lima')->format('H:i').' (Perú), en: '.$entry->pickup_address.'. Cantidad: '.$this->quantity($entry->expected_quantity).' '.$entry->allocation->orderItem->baseUnit->code.'.';
                }
            }
            $cancelled = $event->status === 'cancelled';
            if ($cancelled) {
                $body = $this->prefix($event->order).'Acopio '.$event->order->code.' cancelado. Tu aportación ya no está programada. Consulta con coordinación cualquier mercancía que ya hayas entregado.';
            }
            $this->enqueue(['event_key' => ($cancelled ? 'collection-cancelled:' : 'collection:').$event->id.':version:'.$event->order->version.':producer:'.$producerId, 'order_id' => $event->order_id, 'collection_event_id' => $event->id, 'producer_id' => $producerId, 'body' => $body]);
        }
    }

    public function buyer(CommerceEvent $event): void
    {
        if (! $event->order) {
            return;
        }
        $label = match ($event->action) {
            'payment_proof_uploaded' => 'Comprobante recibido',
            'payment_resubmitted' => 'Aclaración de pago recibida',
            default => config('commerce.event_labels.'.$event->action, 'Actualización registrada'),
        };
        $body = $this->prefix($event->order).'Orden '.$event->order->code.': '.$label.'. ';
        $body .= match ($event->action) {
            'delivery_address_updated' => 'Se actualizaron la dirección, el contacto receptor y el punto de entrega en el mapa. Revisa el destino guardado en la orden.',
            'order_confirmed' => 'Tu compra está registrada por S/ '.Money::format($event->order->total_minor).'. '.($event->order->requested_delivery_on ? 'Solicitaste recibirla el '.$event->order->requested_delivery_on->format('d/m/Y').'. ' : '').'Nuestro equipo coordina el transporte. '.($event->order->synthetic && config('commerce.show_test_labels') ? 'Siguiente paso: simula el registro de pago; no transfieras dinero real. ' : 'Siguiente paso: registra el pago o coordina el método elegido. ').'Coordinación confirmará el horario de entrega. La confirmación de compra no acredita un cobro.',
            'collection_scheduled' => $this->collectionDetails(CollectionEvent::query()->where('order_id', $event->order_id)->findOrFail($event->details['collection_event_id'])).' Los productores recibirán sus instrucciones individuales. Consulta la entrega acordada en tu orden.',
            'payment_declared' => 'Recibimos tu registro de pago'.(($event->details['proof_attached'] ?? false) ? ' y su comprobante' : '').'. Administración verificará el ingreso en la cuenta receptora y te avisaremos aquí del resultado. No repitas esta transferencia mientras se revisa.',
            'payment_proof_uploaded' => 'Adjuntamos el comprobante a tu registro de pago. El ingreso sigue pendiente de verificación; la imagen por sí sola no acredita dinero. Te avisaremos aquí del resultado. No repitas esta transferencia mientras se revisa.',
            'payment_resubmitted' => 'Recibimos la aclaración de tu pago y la vinculamos al mismo registro. Administración revisará el ingreso y te avisaremos aquí del resultado. El saldo sigue pendiente; no repitas esta transferencia mientras se revisa.',
            'payment_under_review' => 'Administración está revisando el ingreso en la cuenta receptora. Tu registro de pago todavía no se ha acreditado al saldo. Te avisaremos aquí cuando se resuelva.',
            'payment_confirmed' => $this->confirmedPaymentDetails($event, true),
            'payment_rejected' => 'Este registro no se acreditó al saldo de la compra. Abre el detalle del pago y consulta con coordinación desde tu cuenta para aclarar o corregir sus datos antes de repetir una transferencia.',
            'shipment_dispatched' => 'Tu producto está en tránsito. Revisa la coordinación de entrega en el detalle de la orden.',
            'shipment_scheduled' => 'El equipo programó el despacho. Consulta la fecha y el destino de tu entrega dentro de la orden.',
            'adjustment_proposed' => 'Hay un ajuste de cantidades o condiciones que necesita tu revisión. Abre la orden para aceptarlo o coordinar una corrección; todavía no se considera aceptado.',
            'adjustment_accepted' => 'Tu aceptación del ajuste quedó registrada. Consulta las cantidades y los importes vigentes en la orden.',
            'shipment_returned', 'shipment_lost' => 'Se registró una incidencia con la mercancía. Coordinación revisará la cantidad afectada y las acciones correspondientes; consulta el seguimiento de tu orden.',
            'refund_authorized' => 'Se autorizó un reembolso, pero la devolución de dinero todavía no está confirmada. Te avisaremos cuando se registre.',
            'refund_confirmed' => 'Se confirmó el reembolso. Revisa su importe y referencia dentro de la orden.',
            'refund_failed', 'refund_uncertain' => 'El reembolso sigue pendiente de confirmación. Administración está revisando el resultado; este aviso no acredita una devolución recibida.',
            'shipment_delivered' => 'Se registró una entrega. Revisa la cantidad entregada y comunica cualquier diferencia a coordinación.',
            'order_closed' => 'La compra, entrega y saldos quedaron conciliados. Conserva este número para futuras consultas.',
            default => 'Consulta el detalle actualizado en tu espacio.',
        };
        $this->enqueue(['event_key' => 'commerce:'.$event->id.':buyer', 'order_id' => $event->order_id, 'user_id' => $event->order->buyer->user_id, 'body' => $body]);
    }

    private function confirmedPaymentDetails(CommerceEvent $event, bool $forBuyer): string
    {
        $payment = Payment::query()->where('order_id', $event->order_id)->find($event->details['payment_id'] ?? null);
        $balance = app(PaymentService::class)->balance($event->order)['buyer_balance_minor'];
        $body = $payment ? 'Ingreso verificado: S/ '.Money::format($payment->amount_minor).'. ' : 'Ingreso verificado. ';
        $body .= $balance > 0
            ? 'Pago parcial confirmado. Saldo pendiente: S/ '.Money::format($balance).'. '
            : 'Pago completo confirmado. Saldo pendiente: S/ 0.00. ';
        if ($balance < 0) {
            $body .= 'Saldo a favor del comprador: S/ '.Money::format(-$balance).'; administración coordinará su conciliación. ';
        }

        return $body.($forBuyer
            ? 'Consulta el seguimiento y la coordinación de entrega dentro de tu orden.'
            : 'El saldo se actualizó automáticamente. Revisa la mercancía recibida y la planificación antes de registrar un despacho.');
    }

    public function coordinators(CommerceEvent $event): void
    {
        $instructions = [
            'delivery_address_updated' => 'El destino de entrega cambió. Revisa la dirección, contacto y punto del mapa antes de organizar el transporte. La fecha solicitada y el recojo no cambian con esta actualización.',
            'payment_declared' => 'El comprador registró un pago. Administración puede revisar su referencia, importe y comprobante en Cobros por revisar, contrastar el abono real y confirmarlo en una sola revisión.',
            'payment_proof_uploaded' => 'El comprador adjuntó un comprobante a su pago pendiente. Administración debe contrastarlo con el ingreso real en la cuenta receptora; la imagen por sí sola no acredita dinero.',
            'payment_resubmitted' => 'El comprador aclaró un pago rechazado. El mismo registro vuelve a revisión y conserva sus datos de operación. Administración debe contrastar el ingreso real antes de confirmarlo; no se ha acreditado al saldo ni se debe pedir otra transferencia sin aclararlo.',
            'payment_under_review' => 'Hay un pago pendiente de resolver. Administración puede confirmar el ingreso verificado o rechazar el registro con su motivo.',
            'payment_confirmed' => '',
            'payment_rejected' => 'Un registro de pago fue rechazado y no se acreditó al saldo. Aclara los datos con el comprador antes de pedirle otra transferencia.',
            'adjustment_accepted' => 'El comprador aceptó un ajuste. Revisa cantidades, planificación de recojos y saldos actualizados.',
            'shipment_returned' => 'Se registró una devolución. Revisa la mercancía y concilia la incidencia.',
            'shipment_lost' => 'Se registró una pérdida. Revisa las cantidades afectadas y resuelve la incidencia.',
            'payout_failed' => 'Un desembolso falló. Administración debe verificar el resultado antes de volver a pagar.',
            'payout_uncertain' => 'Un desembolso tiene resultado incierto. Verifica la operación antes de repetirla.',
            'refund_failed' => 'Un reembolso falló. Revisa el resultado antes de reintentarlo.',
            'refund_uncertain' => 'Un reembolso tiene resultado incierto. Verifica el movimiento antes de repetirlo.',
        ];
        if (! $event->order || ! isset($instructions[$event->action])) {
            return;
        }
        if ($event->action === 'payment_confirmed') {
            $instructions[$event->action] = $this->confirmedPaymentDetails($event, false);
        }

        foreach (User::query()->where('active', true)->whereHas('roles.permissions', fn ($q) => $q->where('code', 'manage-commerce'))->get() as $operator) {
            $this->enqueue([
                'event_key' => 'commerce:'.$event->id.':operator:'.$operator->id,
                'order_id' => $event->order_id,
                'user_id' => $operator->id,
                'body' => $this->prefix($event->order).'Orden '.$event->order->code.': '.$instructions[$event->action],
            ]);
        }
    }

    private function enqueue(array $data): OperationalNotice
    {
        if (config('platform.internal_communication')) {
            $data['body'] = preg_replace('/(?:Escribe|escribe|Responde|responde) AYUDA[^.]*\./u', 'Consulta con coordinación desde tu cuenta.', $data['body']);
            $data['body'] = preg_replace('/Escribe VENDER[^.]*\./u', 'Crea otra oferta desde Mi producción.', $data['body']);
            $data['body'] = str_replace('por WhatsApp', 'en AgroLazo', $data['body']);
        }
        $notice = OperationalNotice::query()->firstOrCreate(['event_key' => $data['event_key']], [...$data, 'notification_uuid' => (string) Str::uuid()]);
        OutboxEvent::enqueue('notice:'.$notice->id, 'commerce_notice', ['notice_id' => $notice->id]);

        return $notice;
    }

    public function cancelCollection(CollectionEvent $event): void
    {
        foreach (OperationalNotice::query()->where('collection_event_id', $event->id)->whereIn('status', ['pending', 'blocked', 'queued'])->get() as $notice) {
            if ($notice->message_id) {
                Message::query()->whereKey($notice->message_id)->whereIn('status', ['queued', 'blocked'])->update(['status' => 'cancelled', 'error_code' => 'schedule_changed']);
            }
            $notice->update(['status' => 'cancelled', 'error_code' => 'schedule_changed']);
        }
    }

    public function deliver(int $noticeId): void
    {
        app(Commerce::class)->transaction(null, null, function () use ($noticeId): void {
            $notice = OperationalNotice::query()->lockForUpdate()->findOrFail($noticeId);
            if ($notice->status !== 'pending') {
                return;
            }
            if (str_starts_with($notice->event_key, 'order-confirmed:') && in_array($notice->order->status, ['cancelled', 'closed'], true)) {
                $notice->update(['status' => 'cancelled', 'error_code' => 'order_changed']);

                return;
            }
            if ($notice->collection_event_id && $notice->event->status !== 'scheduled' && ! str_starts_with($notice->event_key, 'collection-cancelled:')) {
                $notice->update(['status' => 'cancelled', 'error_code' => 'schedule_changed']);

                return;
            }
            if ($notice->user_id) {
                if (! $notice->user->active) {
                    $notice->update(['status' => 'blocked', 'error_code' => 'inactive_user']);

                    return;
                }
                if ($notice->user_id !== $notice->order->buyer->user_id && ! $notice->user->hasPermission('manage-commerce')) {
                    $notice->update(['status' => 'blocked', 'error_code' => 'recipient_access_changed']);

                    return;
                }
                if (! DB::table('notifications')->where('id', $notice->notification_uuid)->exists()) {
                    $notification = new CommerceNotification($notice->body, $notice->order->code, route('commerce.orders.show', $notice->order));
                    $notification->id = $notice->notification_uuid;
                    $notice->user->notifyNow($notification);
                    InboxUpdated::dispatch($notice->user_id, $notice->notification_uuid);
                }
                $notice->update(['status' => 'notified']);

                return;
            }
            if (config('platform.internal_communication')) {
                $user = $notice->producer?->user;
                if (! $user || ! $user->active || ! $user->hasRole('producer')) {
                    $notice->update(['status' => 'blocked', 'error_code' => 'producer_account_required']);

                    return;
                }
                ProducerPortal::notify($user, $notice->body, route('producer.sales'), 'operational:'.$notice->id);
                $notice->update(['status' => 'notified', 'error_code' => null]);

                return;
            }
            $realChannel = $notice->producer->seed_batch === null && self::usesRealChannel($notice->order);
            $conversation = Conversation::query()->where('producer_id', $notice->producer_id)->where('phone_lookup', $notice->producer->phone_lookup)->where('consent', 'granted')
                ->when(! $realChannel, fn ($q) => $q->where('provider', 'fake'), fn ($q) => $q->whereIn('provider', $notice->order->synthetic ? ['evolution'] : ['evolution', 'twilio']))
                ->latest('last_inbound_at')->latest('id')->first();
            if (! $conversation) {
                $notice->update(['status' => 'blocked', 'error_code' => ! self::usesRealChannel($notice->order) ? 'synthetic_simulator_required' : 'contact_or_consent_required']);

                return;
            }
            $body = $notice->body;
            if ($notice->order->synthetic && ! str_contains($body, 'No prepares ni traslades producto')) {
                $body = $this->prefix($notice->order).$body;
            }
            $message = $conversation->messages()->firstOrCreate(['deduplication_key' => 'commerce-notice:'.$notice->id], ['direction' => 'outbound', 'provider' => $conversation->provider, 'account' => $conversation->account, 'body' => $body, 'metadata' => ['notice_id' => $notice->id, 'test_whatsapp' => $notice->order->synthetic && $conversation->provider === 'evolution'], 'status' => 'queued']);
            $notice->update(['status' => 'queued', 'message_id' => $message->id]);
            OutboxEvent::enqueue('deliver:'.$message->id, 'deliver', ['message_id' => $message->id]);
        });
    }

    public function retry(User $actor, OperationalNotice $notice, array $data): OperationalNotice
    {
        return app(Commerce::class)->transaction($actor, 'manage-commerce', function (User $actor) use ($notice, $data): OperationalNotice {
            $data = Validator::make($data, ['key' => 'required|uuid'])->validate();
            $data['notice_id'] = $notice->id;

            return app(Commerce::class)->once($actor, 'retry-operational-notice', $data, function () use ($actor, $notice, $data): OperationalNotice {
                $notice = OperationalNotice::query()->lockForUpdate()->findOrFail($notice->id);
                Commerce::ensure($notice->status === 'blocked' && $notice->message_id === null, 'notice', 'Solo se reintentan avisos bloqueados antes de crear un mensaje. Revisa los mensajes existentes en Mensajería.');
                $notice->update(['status' => 'pending', 'error_code' => null]);
                OutboxEvent::enqueue('notice-retry:'.$notice->id.':'.$data['key'], 'commerce_notice', ['notice_id' => $notice->id]);
                AuditLog::record($actor, 'notice.retry_requested', $notice);

                return $notice;
            });
        });
    }
}
