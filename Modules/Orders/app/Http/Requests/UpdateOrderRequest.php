<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\ApiFormRequest;
use Modules\Orders\DTOs\UpdateOrderData;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Http\Requests\Concerns\OrderItemRules;

/**
 * PATCH /orders/{order}. Every field is optional and only the fields sent are
 * changed; `items`, when sent, replaces the whole list.
 */
class UpdateOrderRequest extends ApiFormRequest
{
    use OrderItemRules;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->orderRules('sometimes') + [
            'status' => ['sometimes', 'string', Rule::enum(OrderStatus::class)],
            'total' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.enum' => __('orders::validation.status_values', ['values' => implode(', ', OrderStatus::values())]),
        ] + $this->orderMessages();
    }

    /**
     * An update that changes nothing is almost always a client bug (a wrong
     * content type, a misspelt field) and is refused instead of returning 200.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->safe()->toArray() === [] && $validator->errors()->isEmpty()) {
                    $validator->errors()->add('body', __('orders::validation.empty_update'));
                }
            },
            fn (Validator $validator) => $this->checkTotalFits($validator),
        ];
    }

    public function toData(): UpdateOrderData
    {
        return UpdateOrderData::fromArray($this->validated());
    }
}
