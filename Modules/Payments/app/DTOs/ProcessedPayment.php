<?php

namespace Modules\Payments\DTOs;

use Modules\Payments\Models\Payment;

/**
 * A payment and whether this call created it or replayed an earlier one with
 * the same Idempotency-Key.
 */
final readonly class ProcessedPayment
{
    public function __construct(
        public Payment $payment,
        public bool $replayed = false,
    ) {}
}
