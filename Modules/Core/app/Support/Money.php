<?php

namespace Modules\Core\Support;

/**
 * Money arithmetic in minor units (cents).
 *
 * Amounts are stored as DECIMAL(12,2) and travel as two-decimal strings, but
 * every sum and product is done on integers. 0.1 + 0.2 is not 0.3 in floating
 * point, and an order total that is off by a cent is an order the payment
 * gateway charges differently from what the customer was shown.
 */
final class Money
{
    public static function toMinor(int|float|string|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    /**
     * A decimal string, always two places, never thousands-separated —
     * the shape the API returns and a gateway expects.
     */
    public static function fromMinor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    public static function normalize(int|float|string|null $amount): string
    {
        return self::fromMinor(self::toMinor($amount));
    }
}
