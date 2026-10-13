<?php

namespace App\Services;

class Money
{
    public static function line(string $quantity, string $unitPrice): int
    {
        $minor = bcadd(bcmul(bcmul($quantity, $unitPrice, 9), '100', 9), '0.5', 0);
        Commerce::ensure(bccomp($minor, '1000000000000', 0) <= 0 && bccomp($minor, '0', 0) >= 0, 'amount', 'El importe excede el límite admitido.');

        return (int) $minor;
    }

    public static function format(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function amount(string $amount): int
    {
        Commerce::ensure((bool) preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/', $amount), 'amount', 'Indica un monto positivo con hasta dos decimales.');
        $minor = (int) bcmul($amount, '100', 0);
        Commerce::ensure($minor > 0, 'amount', 'El monto debe ser mayor que cero.');

        return $minor;
    }

    /** Deterministic largest-remainder distribution; integer cents are conserved. */
    public static function distribute(int $minor, array $weights): array
    {
        $total = array_reduce($weights, fn (string $sum, string $weight): string => bcadd($sum, $weight, 9), '0');
        Commerce::ensure($minor >= 0 && bccomp($total, '0', 9) > 0, 'amount', 'No se puede distribuir el importe.');
        $result = [];
        $remainders = [];
        foreach ($weights as $id => $weight) {
            $raw = bcdiv(bcmul((string) $minor, $weight, 9), $total, 12);
            $result[$id] = (int) bcadd($raw, '0', 0);
            $remainders[$id] = bcsub($raw, (string) $result[$id], 12);
        }
        $ids = array_keys($weights);
        usort($ids, fn ($a, $b): int => bccomp($remainders[$b], $remainders[$a], 12) ?: ($a <=> $b));
        $left = $minor - array_sum($result);
        for ($i = 0; $i < $left; $i++) {
            $result[$ids[$i % count($ids)]]++;
        }

        return $result;
    }
}
