<?php

namespace Modules\Payments\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';

    /**
     * Whether this payment stops another one being started for its order.
     *
     * Successful obviously does. Pending does too: a bank transfer awaiting
     * confirmation may still land, and charging a card on top of it would
     * take the money twice. Failed does not — retrying is the point.
     */
    public function blocksNewPayment(): bool
    {
        return $this !== self::Failed;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
