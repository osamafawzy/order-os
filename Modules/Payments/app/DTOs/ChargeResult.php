<?php

namespace Modules\Payments\DTOs;

use Modules\Payments\Enums\PaymentStatus;

/**
 * What a gateway answered, in the system's terms rather than the vendor's.
 *
 * `meta` is what gets stored and shown back on the payment — a card's brand
 * and last four digits, bank-transfer instructions. It must never carry a full
 * card number, a CVC or a secret.
 */
final readonly class ChargeResult
{
    /** @param array<string, mixed> $meta */
    private function __construct(
        public PaymentStatus $status,
        public ?string $transactionId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $meta = [],
    ) {}

    /** @param array<string, mixed> $meta */
    public static function successful(string $transactionId, array $meta = []): self
    {
        return new self(PaymentStatus::Successful, $transactionId, meta: $meta);
    }

    /**
     * Accepted but not settled: the money arrives later (a bank transfer, a
     * 3-D Secure challenge) and a webhook or reconciliation finishes it.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function pending(?string $transactionId = null, array $meta = []): self
    {
        return new self(PaymentStatus::Pending, $transactionId, meta: $meta);
    }

    /**
     * The gateway did not give an answer and may still have charged. Kept
     * pending, with the reason, until reconciliation settles it.
     */
    public static function unknown(string $code, string $message): self
    {
        return new self(PaymentStatus::Pending, failureCode: $code, failureMessage: $message);
    }

    /** @param array<string, mixed> $meta */
    public static function failed(string $code, string $message, ?string $transactionId = null, array $meta = []): self
    {
        return new self(PaymentStatus::Failed, $transactionId, $code, $message, $meta);
    }
}
