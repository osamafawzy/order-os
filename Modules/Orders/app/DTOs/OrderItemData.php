<?php

namespace Modules\Orders\DTOs;

use Modules\Core\Support\BaseDTO;
use Modules\Core\Support\Money;

class OrderItemData extends BaseDTO
{
    public function __construct(
        public string $product_name,
        public int $quantity,
        public string $price,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            product_name: trim((string) $data['product_name']),
            quantity: (int) $data['quantity'],
            price: Money::normalize($data['price']),
        );
    }

    public function lineTotalMinor(): int
    {
        return Money::toMinor($this->price) * $this->quantity;
    }
}
