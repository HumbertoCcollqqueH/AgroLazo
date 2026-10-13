<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Models\PaymentMethod;

class ManualPaymentGateway implements PaymentGatewayInterface
{
    public function instructions(PaymentMethod $method, bool $synthetic): array
    {
        if ($synthetic && ! config('commerce.show_test_labels')) {
            return ['method' => $method->code, 'name' => $method->name, 'version' => $method->version,
                'recipient' => 'Pago pendiente de coordinación',
                'instructions' => 'Coordina el pago con nuestro equipo antes de transferir dinero. Esta pantalla no realiza cobros.',
                'synthetic' => true, 'automatic_confirmation' => false];
        }

        return ['method' => $method->code, 'name' => $method->name, 'version' => $method->version,
            'recipient' => $synthetic ? 'RECEPTOR SINTÉTICO · NO TRANSFERIR DINERO' : $method->recipient,
            'instructions' => $synthetic ? 'Registra referencias y comprobantes de prueba. No efectúes transferencias, pagos Yape/Plin ni entregas de efectivo reales.' : $method->instructions,
            'synthetic' => $synthetic, 'automatic_confirmation' => false];
    }

    public function automaticallyConfirms(): bool
    {
        return false;
    }
}
