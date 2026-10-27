<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Jumlah barang disimpan sebagai numeric(12,2). Di PHP dipegang sebagai string desimal
 * supaya penjumlahan tidak terkena galat float (0.1 + 0.2).
 */
final class Quantity
{
    /** Ubah ke string dengan dua desimal; tolak nol, negatif, atau lebih dari dua desimal. */
    public static function positive(float|int|string $value): string
    {
        $string = self::normalize($value);

        if (bccomp($string, '0', 2) <= 0) {
            throw new InvalidArgumentException('Jumlah harus lebih dari nol.');
        }

        return $string;
    }

    public static function nonNegative(float|int|string $value): string
    {
        $string = self::normalize($value);

        if (bccomp($string, '0', 2) < 0) {
            throw new InvalidArgumentException('Jumlah tidak boleh negatif.');
        }

        return $string;
    }

    private static function normalize(float|int|string $value): string
    {
        $string = is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : trim((string) $value);

        if (! preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $string)) {
            throw new InvalidArgumentException("Jumlah tidak valid: {$string} (maksimal dua desimal).");
        }

        return bcadd($string, '0', 2);
    }
}
