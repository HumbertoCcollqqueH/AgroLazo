<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('commerce.payment_methods') as $code => $name) {
            PaymentMethod::query()->firstOrCreate(['code' => $code], ['name' => $name, 'recipient' => 'Pendiente de configurar y verificar por administración', 'instructions' => 'No realizar pagos hasta que administración configure y verifique el receptor y las instrucciones.', 'active' => true, 'live_ready' => false, 'version' => 1]);
        }
    }
}
