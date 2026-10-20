<?php

namespace Modules\Payments\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The checksum every card number carries. Catches a mistyped digit before a
 * gateway is ever asked — and before it counts against a fraud score.
 */
class LuhnNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '' || ! self::passes($digits)) {
            $fail('payments::validation.card_number_invalid')->translate();
        }
    }

    public static function passes(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
