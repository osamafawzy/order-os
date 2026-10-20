<?php

namespace Modules\Orders\Http\Requests\Concerns;

use Illuminate\Validation\Validator;
use Modules\Core\Support\Money;
use Modules\Orders\DTOs\CreateOrderData;
use Modules\Orders\Support\OrderTotalCalculator;

/**
 * The rules for an order's customer and items, shared by create and update so
 * the two can never disagree about what a valid item is.
 */
trait OrderItemRules
{
    /**
     * @param  string  $presence  'required' on create, 'sometimes' on update
     * @return array<string, array<int, mixed>>
     */
    protected function orderRules(string $presence): array
    {
        return [
            'customer_name' => [$presence, 'string', 'min:2', 'max:255'],
            'customer_email' => [$presence, 'string', 'email', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,30}$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => [$presence, 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_name,quantity,price'],
            'items.*.product_name' => ['required', 'string', 'min:1', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) __('orders::validation.attributes');
    }

    /**
     * Each line is capped by its own rules, but the sum of lines is not, and
     * orders.total is DECIMAL(12,2). Without this an oversized order reaches
     * the database and fails there as a 500 instead of a 422.
     */
    protected function checkTotalFits(Validator $validator): void
    {
        $items = $this->input('items');

        if (! is_array($items) || $validator->errors()->hasAny(['items', 'items.*'])) {
            return;
        }

        $total = (new OrderTotalCalculator)->calculate(CreateOrderData::items($items))['total'];

        if (Money::toMinor($total) > OrderTotalCalculator::MAX_TOTAL_MINOR) {
            $validator->errors()->add('items', __('orders::validation.total_too_large', [
                'max' => Money::fromMinor(OrderTotalCalculator::MAX_TOTAL_MINOR),
            ]));
        }
    }

    /** @return array<string, string> */
    protected function orderMessages(): array
    {
        return [
            'items.required' => __('orders::validation.items_required'),
            'items.min' => __('orders::validation.items_required'),
            'items.*.price.decimal' => __('orders::validation.price_format'),
        ];
    }
}
