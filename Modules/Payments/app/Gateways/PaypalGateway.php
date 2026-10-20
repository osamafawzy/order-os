<?php

namespace Modules\Payments\Gateways;

use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;

/**
 * A simulated PayPal checkout.
 *
 * The payer is identified by their PayPal email. A payer whose address starts
 * with "declined" is refused, so the failure path can be exercised from a
 * Postman request without a sandbox account; everyone else is approved.
 */
class PaypalGateway extends AbstractGateway
{
    public const DECLINED_PREFIX = 'declined';

    public function rules(): array
    {
        return [
            'paypal_email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $email = mb_strtolower((string) $request->detail('paypal_email'));

        $meta = [
            'payer_email' => $this->mask($email),
            'mode' => $this->config->setting('mode', 'sandbox'),
        ];

        if (str_starts_with($email, self::DECLINED_PREFIX)) {
            return ChargeResult::failed(
                'payer_declined',
                __('payments::gateways.paypal_declined'),
                meta: $meta,
            );
        }

        return ChargeResult::successful($this->reference('PAYID-'), $meta);
    }

    protected function requiredCredentials(): array
    {
        return ['client_id', 'client_secret'];
    }

    /** j***@example.com — recognisable to its owner, useless to anyone else. */
    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
