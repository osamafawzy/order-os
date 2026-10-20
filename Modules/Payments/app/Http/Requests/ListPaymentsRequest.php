<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\ApiFormRequest;
use Modules\Payments\DTOs\PaymentFilters;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Services\GatewayRegistry;

class ListPaymentsRequest extends ApiFormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(GatewayRegistry $gateways): array
    {
        return $this->paginationRules() + [
            'status' => ['sometimes', 'string', Rule::enum(PaymentStatus::class)],
            // Every configured method, not just the available ones: a
            // gateway switched off today still has yesterday's payments.
            'method' => ['sometimes', 'string', Rule::in(array_keys($gateways->all()))],
            'order_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.enum' => __('payments::validation.status_values', ['values' => implode(', ', PaymentStatus::values())]),
        ];
    }

    public function filters(): PaymentFilters
    {
        return PaymentFilters::fromArray($this->safe()->only(['status', 'method', 'order_id']));
    }
}
