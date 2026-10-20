<?php

namespace Modules\Orders\Tests\Unit;

use Modules\Orders\DTOs\CreateOrderData;
use Modules\Orders\DTOs\UpdateOrderData;
use Modules\Orders\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * The DTOs that carry a request into OrderService — in particular the
 * difference between "field not sent" and "field sent as null" on a PATCH.
 */
class UpdateOrderDataTest extends TestCase
{
    public function test_absent_and_null_fields_are_told_apart(): void
    {
        $data = UpdateOrderData::fromArray(['customer_phone' => null]);

        $this->assertTrue($data->has('customer_phone'));
        $this->assertFalse($data->has('notes'));
        $this->assertSame(['customer_phone' => null], $data->attributes());
    }

    public function test_attributes_contain_only_the_fields_that_were_sent(): void
    {
        $data = UpdateOrderData::fromArray([
            'customer_name' => '  Jane Doe ',
            'customer_email' => ' JANE@Example.com ',
        ]);

        $this->assertSame([
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
        ], $data->attributes());
    }

    public function test_a_status_only_update_does_not_change_details(): void
    {
        $data = UpdateOrderData::fromArray(['status' => 'confirmed']);

        $this->assertSame(OrderStatus::Confirmed, $data->status);
        $this->assertFalse($data->changesDetails());
        $this->assertSame([], $data->attributes());
    }

    public function test_items_count_as_a_detail_change(): void
    {
        $data = UpdateOrderData::fromArray([
            'items' => [['product_name' => 'Pen', 'quantity' => 1, 'price' => '1']],
        ]);

        $this->assertTrue($data->changesDetails());
        $this->assertNotNull($data->items);
        $this->assertSame('1.00', $data->items[0]->price);
    }

    public function test_create_data_trims_and_lowercases_customer_fields(): void
    {
        $data = CreateOrderData::fromArray([
            'customer_name' => '  John  ',
            'customer_email' => ' John@Example.COM',
            'customer_phone' => ' +1 555 0100 ',
            'items' => [['product_name' => ' Pen ', 'quantity' => '3', 'price' => '2.5']],
        ]);

        $this->assertSame('John', $data->customer_name);
        $this->assertSame('john@example.com', $data->customer_email);
        $this->assertSame('+1 555 0100', $data->customer_phone);
        $this->assertSame('Pen', $data->items[0]->product_name);
        $this->assertSame(3, $data->items[0]->quantity);
        $this->assertSame('2.50', $data->items[0]->price);
        $this->assertSame(750, $data->items[0]->lineTotalMinor());
    }
}
