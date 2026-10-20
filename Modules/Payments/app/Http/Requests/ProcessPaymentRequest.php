<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\ApiFormRequest;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTOs\ProcessPaymentData;
use Modules\Payments\Services\GatewayRegistry;

/**
 * The fields every payment shares are fixed here; the fields one gateway
 * needs come from that gateway's rules(), nested under `details`. A card
 * needs a number and a CVC, PayPal needs an email — and this class never has
 * to learn either.
 */
class ProcessPaymentRequest extends ApiFormRequest
{
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'method' => ['required', 'string', Rule::in($this->gateways()->availableKeys())],
            'details' => ['sometimes', 'array'],
            // The amount is the order's total, never the client's figure.
            'amount' => ['prohibited'],
        ];

        foreach ($this->selectedGateway()?->rules() ?? [] as $field => $fieldRules) {
            $rules['details.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'method.in' => __('payments::validation.method_in', [
                'methods' => implode(', ', $this->gateways()->availableKeys()),
            ]),
            'amount.prohibited' => __('payments::validation.amount_prohibited'),
        ];
    }

    /**
     * "details.card_number" reads as "card number" in an error message.
     * Derived from the field names so a new gateway needs no lang entry.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (array_keys($this->selectedGateway()?->rules() ?? []) as $field) {
            $attributes['details.'.$field] = str_replace('_', ' ', $field);
        }

        return $attributes;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $key = $this->header(self::IDEMPOTENCY_HEADER);

                if ($key !== null && ! preg_match('/^[A-Za-z0-9_\-:.]{8,255}$/', $key)) {
                    $validator->errors()->add(self::IDEMPOTENCY_HEADER, __('payments::validation.idempotency_key'));
                }
            },
        ];
    }

    public function toData(): ProcessPaymentData
    {
        return ProcessPaymentData::fromArray($this->validated() + [
            'idempotency_key' => $this->header(self::IDEMPOTENCY_HEADER),
        ]);
    }

    /**
     * The gateway the client asked for, if it is one they may use. Unknown
     * or unavailable methods are left to the `method` rule to report.
     */
    private function selectedGateway(): ?PaymentGateway
    {
        $method = $this->input('method');

        return is_string($method) && in_array($method, $this->gateways()->availableKeys(), true)
            ? $this->gateways()->get($method)
            : null;
    }

    /**
     * Resolved from the request's own container: rules(), messages() and
     * attributes() are all called by the framework without arguments to
     * inject into, and the registry is scoped, so this is the same instance
     * the controller and service will use.
     */
    private function gateways(): GatewayRegistry
    {
        return $this->container->make(GatewayRegistry::class);
    }
}
