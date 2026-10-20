<?php

namespace Modules\Payments\DTOs;

use Modules\Core\Support\BaseDTO;
use Modules\Payments\Enums\PaymentStatus;

class PaymentFilters extends BaseDTO
{
    public function __construct(
        public ?PaymentStatus $status = null,
        public ?string $method = null,
        public ?int $order_id = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            status: isset($data['status']) ? PaymentStatus::from($data['status']) : null,
            method: $data['method'] ?? null,
            order_id: isset($data['order_id']) ? (int) $data['order_id'] : null,
        );
    }
}
