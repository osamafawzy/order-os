<?php

namespace Modules\Orders\DTOs;

use Modules\Core\Support\BaseDTO;

class CreateOrderData extends BaseDTO
{
    /**
     * @param  array<int, OrderItemData>  $items
     */
    public function __construct(
        public string $customer_name,
        public string $customer_email,
        public array $items,
        public ?string $customer_phone = null,
        public ?string $notes = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            customer_name: trim((string) $data['customer_name']),
            customer_email: mb_strtolower(trim((string) $data['customer_email'])),
            items: self::items($data['items'] ?? []),
            customer_phone: isset($data['customer_phone']) ? trim((string) $data['customer_phone']) : null,
            notes: $data['notes'] ?? null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, OrderItemData>
     */
    public static function items(array $items): array
    {
        return array_map(
            static fn (array $item): OrderItemData => OrderItemData::fromArray($item),
            array_values($items),
        );
    }
}
