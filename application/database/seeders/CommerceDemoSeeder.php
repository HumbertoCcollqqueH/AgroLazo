<?php

namespace Database\Seeders;

use App\Models\PricingPolicy;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class CommerceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Las reglas sintéticas solo se preparan en local o testing.');
        }
        $actor = User::query()->where('email', 'admin@agroconecta.test')->firstOrFail();
        foreach (Unit::query()->where('active', true)->whereIn('dimension', ['mass', 'count'])->get() as $unit) {
            PricingPolicy::query()->firstOrCreate(['name' => 'Prueba local con retiro · '.$unit->code, 'base_unit_id' => $unit->id], [
                'allocation_rule' => 'fifo', 'fee_unit_price' => '0.100000', 'logistics_minor' => 0,
                'discount_threshold' => '100.000', 'discount_minor' => 100, 'tax_bps' => 0,
                'tax_basis' => 'Escenario sintético sin tributos. No es una regla tributaria para ventas reales.',
                'disclosure' => 'Prueba: servicio de plataforma S/ 0.10 por unidad, financiado por el comprador; descuento de plataforma S/ 1 desde 100 unidades. Retiro coordinado, sin transporte cobrado. El productor conserva el bruto y los costos de su acuerdo.',
                'delivery_mode' => 'collection', 'synthetic' => true, 'active' => true, 'recorded_by' => $actor->id,
            ]);
        }
    }
}
