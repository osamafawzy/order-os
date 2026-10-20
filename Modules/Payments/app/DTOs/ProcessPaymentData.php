<?php

namespace Modules\Payments\DTOs;

use Modules\Core\Support\BaseDTO;

class ProcessPaymentData extends BaseDTO
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $method,
        public array $details = [],
        public ?string $idempotency_key = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            method: (string) $data['method'],
            details: (array) ($data['details'] ?? []),
            idempotency_key: isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
                ? (string) $data['idempotency_key']
                : null,
        );
    }
}
