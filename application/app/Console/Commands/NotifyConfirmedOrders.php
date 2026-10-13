<?php

namespace App\Console\Commands;

use App\Services\OperationalNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:notify {--order= : Recupera los avisos de una orden confirmada o en acopio}')]
#[Description('Recupera avisos de compra faltantes sin duplicarlos ni cambiar pagos, cantidades o acopios')]
class NotifyConfirmedOrders extends Command
{
    public function handle(OperationalNotifier $notifier): int
    {
        $orderId = $this->option('order');
        if ($orderId !== null && (! ctype_digit((string) $orderId) || (int) $orderId < 1)) {
            $this->error('Indica un identificador de orden positivo.');

            return self::FAILURE;
        }
        $count = $notifier->recoverConfirmedOrders($orderId === null ? null : (int) $orderId);
        $this->info('Avisos nuevos para productores: '.$count.'. Las órdenes sintéticas solo usan el simulador; las reales respetan contacto, consentimiento y configuración de mensajería.');

        return self::SUCCESS;
    }
}
