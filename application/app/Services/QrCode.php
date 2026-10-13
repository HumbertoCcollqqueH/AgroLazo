<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * QR Model 2, version 5-L, byte mode, mask 0, for short trace URLs (maximum 106 bytes).
 * Reduced PHP adaptation of Project Nayuki's QR Code generator:
 * https://www.nayuki.io/page/qr-code-generator-library
 *
 * Copyright (c) Project Nayuki. (MIT License)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
class QrCode
{
    private const SIZE = 37;

    /** @return list<list<bool>> */
    public function matrix(string $text): array
    {
        if (strlen($text) > 106 || $text === '') {
            throw new InvalidArgumentException('El QR admite entre 1 y 106 bytes. Configura APP_URL con un dominio o una ruta más cortos.');
        }
        $bits = '0100'.sprintf('%08b', strlen($text));
        foreach (unpack('C*', $text) as $byte) {
            $bits .= sprintf('%08b', $byte);
        }
        $bits .= '0000';
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0');
        $bytes = array_map('bindec', str_split($bits, 8));
        $pad = 0;
        while (count($bytes) < 108) {
            $bytes[] = $pad++ % 2 === 0 ? 0xEC : 0x11;
        }
        $divisor = array_fill(0, 26, 0);
        $divisor[25] = 1;
        $root = 1;
        for ($i = 0; $i < 26; $i++) {
            for ($j = 0; $j < 26; $j++) {
                $divisor[$j] = $this->multiply($divisor[$j], $root) ^ ($divisor[$j + 1] ?? 0);
            }
            $root = $this->multiply($root, 2);
        }
        $remainder = array_fill(0, 26, 0);
        foreach ($bytes as $byte) {
            $factor = $byte ^ array_shift($remainder);
            $remainder[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $remainder[$i] ^= $this->multiply($coefficient, $factor);
            }
        }
        $data = array_merge($bytes, $remainder);
        $modules = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));
        $reserved = $modules;
        $set = function (int $x, int $y, bool $dark) use (&$modules, &$reserved): void {
            if ($x >= 0 && $x < self::SIZE && $y >= 0 && $y < self::SIZE) {
                $modules[$y][$x] = $dark;
                $reserved[$y][$x] = true;
            }
        };
        for ($i = 0; $i < self::SIZE; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [33, 3], [3, 33]] as [$x, $y]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $set($x + $dx, $y + $dy, ! in_array(max(abs($dx), abs($dy)), [2, 4], true));
                }
            }
        }
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $set(30 + $dx, 30 + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
        $format = 8;
        $rem = $format;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $formatBits = (($format << 10) | $rem) ^ 0x5412;
        $bit = fn (int $index): bool => (($formatBits >> $index) & 1) !== 0;
        for ($i = 0; $i < 6; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set(self::SIZE - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, self::SIZE - 15 + $i, $bit($i));
        }
        $set(8, self::SIZE - 8, true);
        $index = 0;
        for ($right = self::SIZE - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < self::SIZE; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? self::SIZE - 1 - $vertical : $vertical;
                    if (! $reserved[$y][$x] && $index < count($data) * 8) {
                        $modules[$y][$x] = (($data[$index >> 3] >> (7 - ($index & 7))) & 1) !== 0;
                        $index++;
                    }
                }
            }
        }
        for ($y = 0; $y < self::SIZE; $y++) {
            for ($x = 0; $x < self::SIZE; $x++) {
                if (! $reserved[$y][$x] && ($x + $y) % 2 === 0) {
                    $modules[$y][$x] = ! $modules[$y][$x];
                }
            }
        }

        return $modules;
    }

    private function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    public function svg(string $text): string
    {
        $path = '';
        foreach ($this->matrix($text) as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M'.($x + 4).','.($y + 4).'h1v1h-1z ';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 45 45" role="img" aria-label="QR de trazabilidad" shape-rendering="crispEdges"><rect width="45" height="45" fill="#fff"/><path d="'.$path.'" fill="#000"/></svg>';
    }
}
