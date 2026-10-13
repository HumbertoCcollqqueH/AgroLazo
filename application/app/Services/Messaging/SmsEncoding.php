<?php

namespace App\Services\Messaging;

class SmsEncoding
{
    /** @return array{encoding: string, units: int, segments: int} */
    public function estimate(string $text): array
    {
        $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $extension = "^{}\\[~]|€\f";
        $units = 0;
        foreach (mb_str_split($text) as $character) {
            if (str_contains($basic, $character)) {
                $units++;
            } elseif (str_contains($extension, $character)) {
                $units += 2;
            } else {
                $units = intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);

                return ['encoding' => 'UCS-2', 'units' => $units, 'segments' => $units <= 70 ? 1 : intdiv($units + 66, 67)];
            }
        }

        return ['encoding' => 'GSM-7', 'units' => $units, 'segments' => $units <= 160 ? 1 : intdiv($units + 152, 153)];
    }
}
