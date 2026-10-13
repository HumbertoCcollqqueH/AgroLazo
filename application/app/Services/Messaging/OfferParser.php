<?php

namespace App\Services\Messaging;

use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class OfferParser
{
    public function normalized(string $text): string
    {
        return Str::upper(Str::ascii(preg_replace('/\s+/u', ' ', trim($text)) ?? ''));
    }

    /** @return array{fields: array, errors: array} */
    public function parse(string $text): array
    {
        $text = $this->normalized($text);
        $fields = [];
        $errors = [];
        $products = Product::query()->with(['category', 'baseUnit'])->where('active', true)->get()->filter(fn (Product $p): bool => $p->isUsable() && $this->containsName($text, $p->name));
        if ($products->isNotEmpty()) {
            $products = $products->reject(fn (Product $product): bool => $products->contains(
                fn (Product $other): bool => $other->id !== $product->id && mb_strlen($other->name) > mb_strlen($product->name)
                    && $this->containsName($this->normalized($other->name), $product->name)
            ));
            if ($products->count() === 1) {
                $fields['product_id'] = $products->first()->id;
            } else {
                $errors[] = 'Hay varios productos posibles; selecciona uno.';
            }
        }
        $quantity = $this->quantity($text);
        if (isset($quantity['error'])) {
            $errors[] = $quantity['error'];
        } else {
            $fields = [...$fields, ...$quantity];
        }
        $locations = Location::query()->where('active', true)->get()->filter(fn (Location $l): bool => $l->isUsable() && $this->containsName($text, $l->name));
        if ($locations->count() === 1) {
            $fields['location_id'] = $locations->first()->id;
        } elseif ($locations->count() > 1) {
            $errors[] = 'La localidad es ambigua; selecciona su referencia.';
        }

        return compact('fields', 'errors');
    }

    private function containsName(string $text, string $name): bool
    {
        return preg_match('/(?<![A-Z0-9])'.preg_quote($this->normalized($name), '/').'(?![A-Z0-9])/u', $text) === 1;
    }

    public function quantity(string $text): array
    {
        $text = $this->normalized($text);
        $units = Unit::query()->where('active', true)->get();
        $aliases = ['kg' => ['KILOGRAMOS', 'KILOS'], 'g' => ['GRAMOS'], 't' => ['TONELADAS'], 'saco' => ['SACOS'], 'unidad' => ['UNIDADES']];
        $matches = [];
        foreach ($units as $unit) {
            $names = array_unique([$this->normalized($unit->code), $this->normalized($unit->name), ...($aliases[$unit->code] ?? [])]);
            $pattern = '/(?<![\d.,-])(-?\d+(?:[.,]\d+)?)\s*(?:'.implode('|', array_map(fn (string $s): string => preg_quote($s, '/'), $names)).')(?![A-Z])/u';
            if (preg_match_all($pattern, $text, $found, PREG_SET_ORDER)) {
                foreach ($found as $match) {
                    $matches[] = ['quantity' => $match[1], 'unit_id' => $unit->id];
                }
            }
        }
        if (count($matches) !== 1) {
            return count($matches) > 1 ? ['error' => 'Indica una sola cantidad y unidad.'] : [];
        }
        $quantity = $matches[0]['quantity'];
        if (preg_match('/[.,]\d{3}$/', $quantity) || ! preg_match('/\A\d{1,15}(?:[.,]\d{1,3})?\z/', $quantity)) {
            return ['error' => 'Cantidad ambigua: no uses separadores de millar; escribe 1000 kg o 1 kg, según corresponda.'];
        }
        $quantity = str_replace(',', '.', $quantity);
        if (bccomp($quantity, '0', 3) <= 0) {
            return ['error' => 'La cantidad debe ser positiva.'];
        }

        return ['quantity' => $quantity, 'unit_id' => $matches[0]['unit_id']];
    }

    public function date(string $text, CarbonImmutable $reference): ?string
    {
        $text = $this->normalized($text);
        $local = $reference->setTimezone('America/Lima')->startOfDay();
        $relativeDays = ['HOY' => 0, 'MANANA' => 1, 'PASADO MANANA' => 2];
        if (array_key_exists($text, $relativeDays)) {
            return $local->addDays($relativeDays[$text])->toDateString();
        }
        if (preg_match('/\AEN (\d{1,4}) DIAS?\z/', $text, $matches) === 1) {
            return $local->addDays((int) $matches[1])->toDateString();
        }

        $date = null;
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $text, $matches) === 1) {
            $date = $this->calendarDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        } elseif (preg_match('/\A(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?\z/', $text, $matches) === 1) {
            $date = $this->calendarDate((int) ($matches[3] ?? $local->year), (int) $matches[2], (int) $matches[1]);
        } elseif (preg_match('/\A(\d{1,2}) DE ([A-Z]+)(?: (?:DE(?:L)? )?(\d{4}))?\z/', $text, $matches) === 1) {
            $months = [
                'ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4,
                'MAYO' => 5, 'JUNIO' => 6, 'JULIO' => 7, 'AGOSTO' => 8,
                'SEPTIEMBRE' => 9, 'SETIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12,
            ];
            if (isset($months[$matches[2]])) {
                $date = $this->calendarDate((int) ($matches[3] ?? $local->year), $months[$matches[2]], (int) $matches[1]);
            }
        }

        return $date !== null && $date >= $local->toDateString() ? $date : null;
    }

    /** Availability durations include the first day; a month is the 30-day menu option. */
    public function availabilityEnd(string $text, string $availableFrom, CarbonImmutable $reference): ?string
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $availableFrom, $matches) !== 1
            || $this->calendarDate((int) $matches[1], (int) $matches[2], (int) $matches[3]) === null) {
            return null;
        }

        $text = $this->normalized($text);
        $durationDays = [
            'SOLO ESE DIA' => 1, 'ESE DIA' => 1, 'EL MISMO DIA' => 1, 'MISMO DIA' => 1,
            'UN DIA' => 1, 'UNA SEMANA' => 7, 'DOS SEMANAS' => 14, 'UN MES' => 30,
        ];
        $days = $durationDays[$text] ?? null;
        if (preg_match('/\A(?:POR |DURANTE )?(\d{1,4}) DIAS?\z/', $text, $matches) === 1) {
            $days = (int) $matches[1];
        }

        if ($days !== null) {
            if ($days < 1) {
                return null;
            }
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $availableFrom, 'America/Lima');
            $date = $from->addDays($days - 1)->toDateString();
        } else {
            $date = $this->date($text, $reference);
        }

        return $date !== null && $date >= $availableFrom && $date >= $reference->setTimezone('America/Lima')->toDateString()
            ? $date
            : null;
    }

    private function calendarDate(int $year, int $month, int $day): ?string
    {
        return $year >= 1 && $year <= 9999 && checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }
}
