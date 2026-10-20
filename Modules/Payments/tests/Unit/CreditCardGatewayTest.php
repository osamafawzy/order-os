<?php

namespace Modules\Payments\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Support\GatewayConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreditCardGatewayTest extends TestCase
{
    private const CVC = '7319';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 10, 20, 12));
    }

    /** @param array<string, mixed> $credentials */
    private function gateway(bool $enabled = true, ?array $credentials = null, array $settings = ['mode' => 'sandbox']): CreditCardGateway
    {
        return new CreditCardGateway(new GatewayConfig(
            key: 'credit_card',
            driver: CreditCardGateway::class,
            name: 'Credit Card',
            enabled: $enabled,
            credentials: $credentials ?? ['public_key' => 'pk_test', 'secret_key' => 'sk_test'],
            settings: $settings,
        ));
    }

    private function charge(string $number, int $month = 12, int $year = 2028, ?CreditCardGateway $gateway = null): ChargeResult
    {
        return ($gateway ?? $this->gateway())->charge(new ChargeRequest(
            paymentId: 7,
            orderId: 3,
            amount: '60.99',
            currency: 'USD',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            details: ['card_number' => $number, 'exp_month' => $month, 'exp_year' => $year, 'cvc' => self::CVC],
        ));
    }

    /** @return array<string, array{0: string, 1: PaymentStatus, 2: string|null, 3: string, 4: string}> */
    public static function cardOutcomes(): array
    {
        return [
            'approved visa' => ['4242424242424242', PaymentStatus::Successful, null, 'visa', '4242'],
            'approved visa typed with spaces' => ['4242 4242 4242 4242', PaymentStatus::Successful, null, 'visa', '4242'],
            'declined' => [CreditCardGateway::CARD_DECLINED, PaymentStatus::Failed, 'card_declined', 'visa', '0002'],
            'insufficient funds' => [CreditCardGateway::CARD_INSUFFICIENT_FUNDS, PaymentStatus::Failed, 'insufficient_funds', 'visa', '9995'],
            'declined typed with spaces' => ['4000 0000 0000 0002', PaymentStatus::Failed, 'card_declined', 'visa', '0002'],
            'any other luhn-valid mastercard is approved' => ['5555555555554444', PaymentStatus::Successful, null, 'mastercard', '4444'],
            'mastercard 2-series' => ['2223003122003222', PaymentStatus::Successful, null, 'mastercard', '3222'],
            'amex' => ['378282246310005', PaymentStatus::Successful, null, 'amex', '0005'],
            'discover' => ['6011111111111117', PaymentStatus::Successful, null, 'discover', '1117'],
            'unknown brand' => ['30569309025904', PaymentStatus::Successful, null, 'unknown', '5904'],
        ];
    }

    #[DataProvider('cardOutcomes')]
    public function test_magic_card_numbers_produce_deterministic_outcomes(
        string $number,
        PaymentStatus $status,
        ?string $failureCode,
        string $brand,
        string $last4,
    ): void {
        $result = $this->charge($number);

        $this->assertSame($status, $result->status);
        $this->assertSame($failureCode, $result->failureCode);
        $this->assertSame($failureCode === null, $result->failureMessage === null);
        $this->assertSame(['card_brand' => $brand, 'card_last4' => $last4, 'mode' => 'sandbox'], $result->meta);
        $this->assertMatchesRegularExpression('/^ch_[A-Z0-9]{24}$/', (string) $result->transactionId);
    }

    #[DataProvider('cardOutcomes')]
    public function test_the_result_never_contains_the_full_card_number_or_cvc(string $number, PaymentStatus $status, ?string $failureCode, string $brand, string $last4): void
    {
        $digits = (string) preg_replace('/\D/', '', $number);
        $result = $this->charge($number);
        $serialized = json_encode([
            'meta' => $result->meta,
            'failure' => $result->failureMessage,
        ]);

        $this->assertStringEndsWith($last4, $digits);

        $this->assertStringNotContainsString($digits, (string) $serialized);
        $this->assertStringNotContainsString($number, (string) $serialized);
        $this->assertStringNotContainsString(self::CVC, (string) $serialized);
    }

    public function test_declines_carry_human_readable_messages(): void
    {
        $this->assertSame('Your card was declined.', $this->charge(CreditCardGateway::CARD_DECLINED)->failureMessage);
        $this->assertSame('Your card has insufficient funds.', $this->charge(CreditCardGateway::CARD_INSUFFICIENT_FUNDS)->failureMessage);
    }

    public function test_an_expired_card_is_declined_without_a_charge(): void
    {
        $result = $this->charge('4242424242424242', month: 9, year: 2026);

        $this->assertSame(PaymentStatus::Failed, $result->status);
        $this->assertSame('expired_card', $result->failureCode);
        $this->assertSame('Your card has expired.', $result->failureMessage);
        $this->assertNull($result->transactionId, 'An expired card never reaches the processor.');
        $this->assertSame('4242', $result->meta['card_last4']);
    }

    public function test_expiry_beats_a_magic_decline_number(): void
    {
        $this->assertSame('expired_card', $this->charge(CreditCardGateway::CARD_DECLINED, month: 1, year: 2026)->failureCode);
    }

    public function test_a_card_is_valid_through_the_last_day_of_its_expiry_month(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 31, 23, 59, 59));
        $this->assertSame(PaymentStatus::Successful, $this->charge('4242424242424242', month: 10, year: 2026)->status);

        $this->travelTo(CarbonImmutable::create(2026, 11, 1, 0, 0, 1));
        $this->assertSame('expired_card', $this->charge('4242424242424242', month: 10, year: 2026)->failureCode);
    }

    public function test_the_mode_setting_is_reported_in_meta(): void
    {
        $result = $this->charge('4242424242424242', gateway: $this->gateway(settings: ['mode' => 'live']));

        $this->assertSame('live', $result->meta['mode']);
    }

    public function test_every_charge_gets_a_unique_transaction_id(): void
    {
        $this->assertNotSame($this->charge('4242424242424242')->transactionId, $this->charge('4242424242424242')->transactionId);
    }

    /** @return array<string, array{0: bool, 1: array<string, mixed>, 2: bool}> */
    public static function availability(): array
    {
        return [
            'enabled and configured' => [true, ['public_key' => 'pk', 'secret_key' => 'sk'], true],
            'disabled' => [false, ['public_key' => 'pk', 'secret_key' => 'sk'], false],
            'secret key missing' => [true, ['public_key' => 'pk'], false],
            'secret key null' => [true, ['public_key' => 'pk', 'secret_key' => null], false],
            'public key blank' => [true, ['public_key' => '', 'secret_key' => 'sk'], false],
            'public key whitespace' => [true, ['public_key' => '   ', 'secret_key' => 'sk'], false],
            'no credentials' => [true, [], false],
        ];
    }

    /** @param array<string, mixed> $credentials */
    #[DataProvider('availability')]
    public function test_is_available_only_when_enabled_with_every_credential(bool $enabled, array $credentials, bool $expected): void
    {
        $this->assertSame($expected, $this->gateway($enabled, $credentials)->isAvailable());
    }

    public function test_identity_comes_from_config(): void
    {
        $this->assertSame('credit_card', $this->gateway()->key());
        $this->assertSame('Credit Card', $this->gateway()->name());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string|null}> */
    public static function detailRules(): array
    {
        $valid = ['card_number' => '4242424242424242', 'exp_month' => 12, 'exp_year' => 2028, 'cvc' => '123'];

        return [
            'valid' => [$valid, null],
            'four digit cvc' => [['cvc' => '1234'] + $valid, null],
            'this month this year' => [['exp_month' => 10, 'exp_year' => 2026] + $valid, null],
            'number fails luhn' => [['card_number' => '4242424242424241'] + $valid, 'card_number'],
            'number too short' => [['card_number' => '42424242'] + $valid, 'card_number'],
            'number with letters' => [['card_number' => '4242abcd42424242'] + $valid, 'card_number'],
            'month 13' => [['exp_month' => 13] + $valid, 'exp_month'],
            'month 0' => [['exp_month' => 0] + $valid, 'exp_month'],
            'last year' => [['exp_year' => 2025] + $valid, 'exp_year'],
            'too far in the future' => [['exp_year' => 2047] + $valid, 'exp_year'],
            'cvc too short' => [['cvc' => '12'] + $valid, 'cvc'],
            'cvc not digits' => [['cvc' => 'abc'] + $valid, 'cvc'],
        ];
    }

    /** @param array<string, mixed> $details */
    #[DataProvider('detailRules')]
    public function test_rules_validate_card_details(array $details, ?string $failingField): void
    {
        $validator = Validator::make($details, $this->gateway()->rules());

        if ($failingField === null) {
            $this->assertTrue($validator->passes(), (string) json_encode($validator->errors()->toArray()));

            return;
        }

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey($failingField, $validator->errors()->toArray());
    }
}
