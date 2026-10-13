<?php

namespace App\Services;

use App\Models\Unit;

class PurchaseQuantity
{
    public static function minimum(Unit $unit): ?string
    {
        if ($unit->dimension === 'count') {
            return '1.000';
        }

        $kilograms = (string) config('commerce.minimum_purchase_kg', 25);
        if (bccomp($kilograms, '0', 6) <= 0) {
            return '0.001';
        }
        $minimum = match ($unit->code) {
            'kg' => bcadd($kilograms, '0', 3),
            'g' => bcmul($kilograms, '1000', 3),
            't' => bcdiv($kilograms, '1000', 3),
            default => null,
        };

        return $minimum === null ? null : (bccomp($minimum, '0.001', 3) < 0 ? '0.001' : $minimum);
    }

    public static function allows(Unit $unit, string $quantity): bool
    {
        $minimum = self::minimum($unit);

        return $minimum !== null && bccomp($quantity, $minimum, 3) >= 0;
    }

    public static function validate(Unit $unit, string $quantity): void
    {
        $minimum = self::minimum($unit);
        Commerce::ensure($minimum !== null, 'quantity', 'El equipo debe configurar la equivalencia de esta unidad antes de cotizar.');
        Commerce::ensure(bccomp($quantity, $minimum, 3) >= 0, 'quantity', 'La compra mínima es '.self::format($minimum).' '.$unit->code.'. Puedes comprar una parte del lote desde esa cantidad.');
    }

    public static function format(string $quantity): string
    {
        return rtrim(rtrim(bcadd($quantity, '0', 3), '0'), '.');
    }
}
