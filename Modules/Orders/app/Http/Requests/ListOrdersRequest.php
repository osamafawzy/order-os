<?php

namespace Modules\Orders\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\ApiFormRequest;
use Modules\Orders\Enums\OrderStatus;

class ListOrdersRequest extends ApiFormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->paginationRules() + [
            'status' => ['sometimes', 'string', Rule::enum(OrderStatus::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.enum' => __('orders::validation.status_values', ['values' => implode(', ', OrderStatus::values())]),
        ];
    }

    public function status(): ?OrderStatus
    {
        return $this->filled('status') ? OrderStatus::from((string) $this->input('status')) : null;
    }
}
