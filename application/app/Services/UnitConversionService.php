<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitConversion;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class UnitConversionService
{
    public const SI_FACTORS = ['g' => '0.001', 'kg' => '1', 't' => '1000'];

    /** @return array{quantity:string, factor:string, conversion_id:?int, source:string} */
    public function convert(string $quantity, int $fromUnitId, int $toUnitId, int $productId, string $context = '', ?string $date = null): array
    {
        if (! preg_match('/^\\d{1,15}(?:\\.\\d{1,3})?$/D', $quantity) || bccomp($quantity, '0', 3) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Indica una cantidad positiva, con hasta 15 enteros y 3 decimales.']);
        }

        $date ??= now()->timezone('America/Lima')->toDateString();
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => 'La fecha no es válida.']);
        }

        $product = Product::query()->with(['category', 'baseUnit'])->find($productId);
        $from = Unit::query()->find($fromUnitId);
        $to = Unit::query()->find($toUnitId);
        if (! $product?->isUsable() || $product->base_unit_id !== $toUnitId) {
            throw ValidationException::withMessages(['product_id' => 'Selecciona un producto disponible y su unidad base.']);
        }
        if (! $from?->active || ! $to?->active) {
            throw ValidationException::withMessages(['from_unit_id' => 'La unidad no está disponible.']);
        }

        $conversionId = null;
        $factor = '1.000000000';
        $source = 'Misma unidad';
        if ($fromUnitId !== $toUnitId) {
            if ($from->dimension !== $to->dimension && $from->dimension !== 'package') {
                throw ValidationException::withMessages(['from_unit_id' => 'No se puede convertir entre masa y conteo.']);
            }

            if (isset(self::SI_FACTORS[$from->code], self::SI_FACTORS[$to->code]) && $from->dimension === 'mass' && $to->dimension === 'mass') {
                $factor = bcdiv(self::SI_FACTORS[$from->code], self::SI_FACTORS[$to->code], 9);
                $source = 'Equivalencia SI';
            } else {
                $conversions = UnitConversion::query()
                    ->where('product_id', $productId)->where('from_unit_id', $fromUnitId)->where('to_unit_id', $toUnitId)
                    ->where('context', $context)->where('active', true)->whereNotNull('verified_by')->whereNotNull('verified_at')
                    ->where('verified_at', '<=', CarbonImmutable::parse($date, 'America/Lima')->endOfDay()->utc())
                    ->whereDate('valid_from', '<=', $date)
                    ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
                    ->limit(2)->get();

                if ($conversions->count() !== 1) {
                    throw ValidationException::withMessages(['context' => $conversions->isEmpty()
                        ? 'No existe una conversión aprobada y vigente para ese producto y contexto.'
                        : 'Hay conversiones ambiguas. Revisa sus vigencias antes de continuar.']);
                }
                $conversion = $conversions->first();
                $factor = $conversion->factor;
                $source = $conversion->source;
                $conversionId = $conversion->id;
            }
        }

        $normalized = bcadd(bcmul($quantity, $factor, 12), '0.0005', 3);
        if (bccomp($normalized, '0', 3) <= 0 || bccomp($normalized, '999999999999999.999', 3) > 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad resultante está fuera de la precisión admitida.']);
        }

        return ['quantity' => $normalized, 'factor' => $factor, 'conversion_id' => $conversionId, 'source' => $source];
    }
}
