<?php

namespace Modules\Orders\Tests\Support;

trait OrderPayloads
{
    /**
     * A valid create-order body. Totals to 60.99.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function orderPayload(array $overrides = []): array
    {
        return array_replace([
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+1 555 0100',
            'notes' => 'Leave at the door',
            'items' => [
                ['product_name' => 'Wireless Mouse', 'quantity' => 2, 'price' => '25.50'],
                ['product_name' => 'USB-C Cable', 'quantity' => 1, 'price' => '9.99'],
            ],
        ], $overrides);
    }
}
