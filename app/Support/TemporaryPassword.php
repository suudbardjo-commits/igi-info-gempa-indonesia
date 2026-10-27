<?php

namespace App\Support;

/** Kata sandi sementara yang mudah dibacakan: tanpa karakter yang mudah tertukar (0/O, 1/l/I). */
class TemporaryPassword
{
    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    public static function make(int $length = 12): string
    {
        $pick = fn (string $set) => $set[random_int(0, strlen($set) - 1)];
        $all = self::LOWER.self::UPPER.self::DIGITS;

        // Satu dari tiap jenis dijamin ada, sisanya acak; lalu diacak urutannya dengan pengacak kriptografis.
        $chars = [$pick(self::LOWER), $pick(self::UPPER), $pick(self::DIGITS)];

        while (count($chars) < $length) {
            $chars[] = $pick($all);
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
