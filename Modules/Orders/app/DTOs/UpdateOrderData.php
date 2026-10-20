<?php

namespace Modules\Orders\DTOs;

use Modules\Core\Support\BaseDTO;
use Modules\Orders\Enums\OrderStatus;

/**
 * A partial update.
 *
 * "Not sent" and "sent as null" mean different things for a PATCH — leaving
 * the phone number alone versus clearing it — so the DTO remembers which
 * fields were actually present instead of treating every null as absent.
 */
class UpdateOrderData extends BaseDTO
{
    /**
     * @param  array<int, OrderItemData>|null  $items
     * @param  array<int, string>  $present
     */
    public function __construct(
        public ?string $customer_name = null,
        public ?string $customer_email = null,
        public ?string $customer_phone = null,
        public ?string $notes = null,
        public ?array $items = null,
        public ?OrderStatus $status = null,
        private readonly array $present = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            customer_name: isset($data['customer_name']) ? trim((string) $data['customer_name']) : null,
            customer_email: isset($data['customer_email']) ? mb_strtolower(trim((string) $data['customer_email'])) : null,
            customer_phone: isset($data['customer_phone']) ? trim((string) $data['customer_phone']) : null,
            notes: $data['notes'] ?? null,
            items: isset($data['items']) ? CreateOrderData::items($data['items']) : null,
            status: isset($data['status']) ? OrderStatus::from($data['status']) : null,
            present: array_keys($data),
        );
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }

    /**
     * The plain columns this update touches, ready for the repository.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (['customer_name', 'customer_email', 'customer_phone', 'notes'] as $field) {
            if ($this->has($field)) {
                $attributes[$field] = $this->{$field};
            }
        }

        return $attributes;
    }

    /**
     * Anything other than a status change. Used to refuse edits to an order
     * that is no longer a draft.
     */
    public function changesDetails(): bool
    {
        return $this->attributes() !== [] || $this->items !== null;
    }
}
