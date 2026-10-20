<?php

namespace Modules\Orders\Support;

use Modules\Core\Support\Money;
use Modules\Orders\DTOs\OrderItemData;

/**
 * Turns a list of items into stored line totals and an order total.
 *
 * The client never sends a total. Accepting one would mean either trusting it
 * or validating it against this calculation — and the second is just this
 * calculation with an extra way to fail.
 */
final class OrderTotalCalculator
{
    /** The largest total DECIMAL(12,2) holds: 9,999,999,999.99. */
    public const MAX_TOTAL_MINOR = 999_999_999_999;

    /**
     * @param  array<int, OrderItemData>  $items
     * @return array{lines: array<int, array{product_name: string, quantity: int, price: string, line_total: string}>, total: string}
     */
    public function calculate(array $items): array
    {
        $lines = [];
        $totalMinor = 0;

        foreach ($items as $item) {
            $lineMinor = $item->lineTotalMinor();
            $totalMinor += $lineMinor;

            $lines[] = [
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'line_total' => Money::fromMinor($lineMinor),
            ];
        }

        return [
            'lines' => $lines,
            'total' => Money::fromMinor($totalMinor),
        ];
    }
}
