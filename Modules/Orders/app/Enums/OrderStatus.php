<?php

namespace Modules\Orders\Enums;

/**
 * Where an order is in its life.
 *
 *     pending ──► confirmed ──► cancelled
 *        │                        ▲
 *        └────────────────────────┘
 *
 * Pending is a draft: everything about it can still change. Confirming locks
 * the items and the total, because that total is what a payment will charge.
 * Cancelled is terminal.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Items, customer details and notes can only change on a draft. Once an
     * order is confirmed, the amount a gateway is asked to charge must be the
     * amount the customer agreed to.
     */
    public function isEditable(): bool
    {
        return $this === self::Pending;
    }

    public function acceptsPayment(): bool
    {
        return $this === self::Confirmed;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
