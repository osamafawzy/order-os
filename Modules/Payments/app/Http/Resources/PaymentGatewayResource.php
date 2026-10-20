<?php

namespace Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payments\Contracts\PaymentGateway;

/**
 * What a client needs to build a payment form for a gateway: its key, whether
 * it can be used right now, and which `details` fields it expects. No
 * credential or setting is ever exposed.
 *
 * @property PaymentGateway $resource
 */
class PaymentGatewayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fields = [];

        foreach ($this->resource->rules() as $field => $rules) {
            $rules = is_string($rules) ? explode('|', $rules) : $rules;

            $fields[] = [
                'name' => $field,
                'required' => in_array('required', $rules, true),
            ];
        }

        return [
            'key' => $this->resource->key(),
            'name' => $this->resource->name(),
            'available' => $this->resource->isAvailable(),
            'fields' => $fields,
        ];
    }
}
