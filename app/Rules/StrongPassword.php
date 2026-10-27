<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Aturan kata sandi IGI: minimal 10 karakter, memuat huruf dan angka. Pesan dalam bahasa Indonesia. */
class StrongPassword implements ValidationRule
{
    public const MIN = 10;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = (string) $value;

        if (mb_strlen($text) < self::MIN) {
            $fail('Kata sandi minimal '.self::MIN.' karakter.');
        } elseif (! preg_match('/\pL/u', $text) || ! preg_match('/\pN/u', $text)) {
            $fail('Kata sandi harus memuat huruf dan angka.');
        }
    }
}
