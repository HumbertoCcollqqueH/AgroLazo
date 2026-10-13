<?php

return [
    'live_enabled' => env('COMMERCE_LIVE_ENABLED', false),
    'test_whatsapp_enabled' => env('COMMERCE_TEST_WHATSAPP_ENABLED', false),
    'show_test_labels' => env('COMMERCE_SHOW_TEST_LABELS', true),
    'minimum_purchase_kg' => env('COMMERCE_MINIMUM_PURCHASE_KG', 25),
    'quote_minutes' => 20,
    'reservation_minutes' => 10,
    'status_labels' => [
        'issued' => 'Emitida', 'converted' => 'Convertida en compra', 'active' => 'Activa', 'expired' => 'Vencida', 'cancelled' => 'Cancelada',
        'confirmed' => 'Confirmado', 'collecting' => 'En acopio', 'ready' => 'Lista para despacho', 'in_transit' => 'En tránsito', 'delivered' => 'Entregado', 'closed' => 'Cerrada',
        'proposed' => 'Propuesto', 'accepted' => 'Aceptado', 'rejected' => 'Rechazado', 'scheduled' => 'Programado', 'completed' => 'Completado', 'failed' => 'Fallido',
        'open' => 'Abierta', 'resolved' => 'Resuelta', 'pending' => 'Pendiente', 'blocked' => 'Bloqueado', 'queued' => 'En cola', 'notified' => 'Notificado en la aplicación',
        'proof_uploaded' => 'Comprobante adjunto', 'under_review' => 'En revisión', 'partially_refunded' => 'Devuelto parcialmente', 'refunded' => 'Devuelto', 'authorized' => 'Autorizado', 'uncertain' => 'Resultado incierto', 'draft' => 'Borrador', 'approved' => 'Aprobada',
    ],
    'event_labels' => [
        'delivery_address_updated' => 'Destino de entrega actualizado',
        'order_confirmed' => 'Compra confirmada', 'adjustment_proposed' => 'Ajuste propuesto al comprador', 'adjustment_accepted' => 'Ajuste aceptado por el comprador',
        'collection_scheduled' => 'Acopio programado', 'receipt_recorded' => 'Recepción y pesaje registrados', 'physical_lot_created' => 'Mercancía consolidada en lote físico',
        'shipment_scheduled' => 'Despacho programado', 'shipment_dispatched' => 'Mercancía despachada', 'shipment_delivered' => 'Entrega registrada', 'shipment_returned' => 'Devolución de mercancía registrada', 'shipment_lost' => 'Pérdida registrada', 'incident_resolved' => 'Incidencia conciliada',
        'payment_declared' => 'Cobro declarado para revisión', 'payment_under_review' => 'Cobro en revisión', 'payment_confirmed' => 'Ingreso confirmado', 'payment_rejected' => 'Registro de cobro rechazado',
        'refund_authorized' => 'Reembolso autorizado', 'refund_confirmed' => 'Reembolso confirmado', 'refund_uncertain' => 'Reembolso de resultado incierto', 'refund_failed' => 'Reembolso fallido',
        'settlement_calculated' => 'Liquidación de productor calculada', 'settlement_approved' => 'Liquidación de productor aprobada', 'payout_confirmed' => 'Desembolso al productor confirmado', 'payout_uncertain' => 'Desembolso de resultado incierto', 'payout_failed' => 'Desembolso fallido', 'order_closed' => 'Orden cerrada y conciliada',
    ],
    'payment_methods' => ['yape' => 'Yape', 'plin' => 'Plin', 'bank' => 'Transferencia bancaria', 'cash' => 'Presencial', 'coordinated' => 'Pago coordinado'],
];
