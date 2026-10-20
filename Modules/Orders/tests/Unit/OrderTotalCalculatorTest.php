<?php

namespace Modules\Orders\Tests\Unit;

use Modules\Orders\DTOs\OrderItemData;
use Modules\Orders\Support\OrderTotalCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderTotalCalculatorTest extends TestCase
{
    private OrderTotalCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new OrderTotalCalculator;
    }

    /** @param array<int, array{0: string, 1: int, 2: int|float|string}> $items */
    private function items(array $items): array
    {
        return array_map(
            static fn (array $i): OrderItemData => OrderItemData::fromArray([
                'product_name' => $i[0],
                'quantity' => $i[1],
                'price' => $i[2],
            ]),
            $items,
        );
    }

    public function test_it_computes_line_totals_and_the_order_total(): void
    {
        $result = $this->calculator->calculate($this->items([
            ['Wireless Mouse', 2, '25.50'],
            ['USB-C Cable', 1, '9.99'],
        ]));

        $this->assertSame('60.99', $result['total']);
        $this->assertSame([
            ['product_name' => 'Wireless Mouse', 'quantity' => 2, 'price' => '25.50', 'line_total' => '51.00'],
            ['product_name' => 'USB-C Cable', 'quantity' => 1, 'price' => '9.99', 'line_total' => '9.99'],
        ], $result['lines']);
    }

    public function test_no_items_totals_zero(): void
    {
        $this->assertSame(['lines' => [], 'total' => '0.00'], $this->calculator->calculate([]));
    }

    /** @return array<string, array{0: array<int, array{0: string, 1: int, 2: int|float|string}>, 1: string}> */
    public static function floatingPointTraps(): array
    {
        return [
            '0.1 + 0.2' => [[['A', 1, '0.1'], ['B', 1, '0.2']], '0.30'],
            '0.1 x 3' => [[['A', 3, '0.10']], '0.30'],
            '19.99 x 3' => [[['A', 3, '19.99']], '59.97'],
            '1.15 x 100' => [[['A', 100, '1.15']], '115.00'],
            '0.7 + 0.1' => [[['A', 1, 0.7], ['B', 1, 0.1]], '0.80'],
            'ten lines of 0.01' => [array_fill(0, 10, ['A', 1, '0.01']), '0.10'],
            'max price x max quantity' => [[['A', 10000, '999999.99']], '9999999900.00'],
        ];
    }

    /** @param array<int, array{0: string, 1: int, 2: int|float|string}> $items */
    #[DataProvider('floatingPointTraps')]
    public function test_totals_are_exact_to_the_cent(array $items, string $expected): void
    {
        $this->assertSame($expected, $this->calculator->calculate($this->items($items))['total']);
    }

    public function test_prices_are_normalised_to_two_decimals_on_each_line(): void
    {
        $result = $this->calculator->calculate($this->items([['A', 2, '5'], ['B', 1, 7.5]]));

        $this->assertSame('5.00', $result['lines'][0]['price']);
        $this->assertSame('10.00', $result['lines'][0]['line_total']);
        $this->assertSame('7.50', $result['lines'][1]['price']);
        $this->assertSame('17.50', $result['total']);
    }
}
