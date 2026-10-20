<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\ApiFormRequest;
use Modules\Orders\DTOs\CreateOrderData;
use Modules\Orders\Http\Requests\Concerns\OrderItemRules;

class StoreOrderRequest extends ApiFormRequest
{
    use OrderItemRules;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->orderRules('required') + [
            // Refused rather than ignored: a client that sends a status on
            // create believes it is setting one, and should be told it isn't.
            'status' => ['prohibited'],
            'total' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.prohibited' => __('orders::validation.status_on_create'),
        ] + $this->orderMessages();
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkTotalFits($validator),
        ];
    }

    public function toData(): CreateOrderData
    {
        return CreateOrderData::fromArray($this->validated());
    }
}
