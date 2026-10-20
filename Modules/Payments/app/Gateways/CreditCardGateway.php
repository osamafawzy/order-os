<?php

namespace Modules\Payments\Gateways;

use Carbon\CarbonImmutable;
use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Rules\LuhnNumber;

/**
 * A simulated card processor, shaped like Stripe's test mode.
 *
 * Magic card numbers produce each outcome deterministically:
 *
 *     4242 4242 4242 4242   approved
 *     4000 0000 0000 0002   declined (card_declined)
 *     4000 0000 0000 9995   declined (insufficient_funds)
 *
 * Any other Luhn-valid number is approved. A real integration replaces only
 * the body of charge() with the vendor SDK call; the contract, the stored
 * fields and every caller stay the same.
 *
 * The full number and the CVC exist only for the length of this method. What
 * is kept is the brand and the last four digits — enough for a customer to
 * recognise the card, and nothing that puts the database in PCI scope.
 */
class CreditCardGateway extends AbstractGateway
{
    public const CARD_DECLINED = '4000000000000002';

    public const CARD_INSUFFICIENT_FUNDS = '4000000000009995';

    public function rules(): array
    {
        return [
            'card_number' => ['required', 'string', 'regex:/^[0-9 ]{12,23}$/', new LuhnNumber],
            'exp_month' => ['required', 'integer', 'between:1,12'],
            'exp_year' => ['required', 'integer', 'min:'.now()->year, 'max:'.(now()->year + 20)],
            'cvc' => ['required', 'string', 'regex:/^[0-9]{3,4}$/'],
            'holder_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        $number = preg_replace('/\D/', '', (string) $request->detail('card_number'));

        $meta = [
            'card_brand' => $this->brand($number),
            'card_last4' => substr($number, -4),
            'mode' => $this->config->setting('mode', 'sandbox'),
        ];

        if ($this->isExpired((int) $request->detail('exp_month'), (int) $request->detail('exp_year'))) {
            return ChargeResult::failed('expired_card', __('payments::gateways.card_expired'), meta: $meta);
        }

        return match ($number) {
            self::CARD_DECLINED => ChargeResult::failed(
                'card_declined',
                __('payments::gateways.card_declined'),
                $this->reference('ch_'),
                $meta,
            ),
            self::CARD_INSUFFICIENT_FUNDS => ChargeResult::failed(
                'insufficient_funds',
                __('payments::gateways.insufficient_funds'),
                $this->reference('ch_'),
                $meta,
            ),
            default => ChargeResult::successful($this->reference('ch_'), $meta),
        };
    }

    protected function requiredCredentials(): array
    {
        return ['public_key', 'secret_key'];
    }

    /**
     * Valid through the last day of the expiry month, as printed cards are.
     */
    private function isExpired(int $month, int $year): bool
    {
        return CarbonImmutable::create($year, $month, 1)->endOfMonth()->isPast();
    }

    private function brand(string $number): string
    {
        return match (true) {
            str_starts_with($number, '4') => 'visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $number) => 'mastercard',
            (bool) preg_match('/^3[47]/', $number) => 'amex',
            str_starts_with($number, '6') => 'discover',
            default => 'unknown',
        };
    }
}
