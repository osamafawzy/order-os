<?php

namespace Modules\Payments\Gateways;

use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;

/**
 * An offline method: the customer is given transfer instructions and the
 * payment stays pending until the money is seen on the account.
 *
 * It is here to prove the contract handles more than "charge and know
 * immediately" — the third payment status exists for gateways like this one.
 * Settling it is a reconciliation job or a bank webhook, out of scope here.
 */
class BankTransferGateway extends AbstractGateway
{
    public function rules(): array
    {
        return [
            'account_holder' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $reference = 'ORD-'.$request->orderId.'-PAY-'.$request->paymentId;

        return ChargeResult::pending($reference, array_filter([
            // Who the money is expected from, to match against the statement.
            'account_holder' => $request->detail('account_holder'),
            'instructions' => [
                'beneficiary' => $this->config->setting('beneficiary'),
                'bank_name' => $this->config->setting('bank_name'),
                'iban' => $this->config->setting('iban'),
                'amount' => $request->amount,
                'currency' => $request->currency,
                // Must be quoted on the transfer, or reconciliation cannot
                // match the money to the order.
                'reference' => $reference,
            ],
        ]));
    }

    protected function requiredSettings(): array
    {
        return ['iban'];
    }
}
