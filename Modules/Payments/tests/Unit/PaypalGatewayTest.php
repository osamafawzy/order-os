<?php

namespace Modules\Payments\Tests\Unit;

use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Gateways\PaypalGateway;
use Modules\Payments\Support\GatewayConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaypalGatewayTest extends TestCase
{
    /** @param array<string, mixed> $credentials */
    private function gateway(bool $enabled = true, ?array $credentials = null): PaypalGateway
    {
        return new PaypalGateway(new GatewayConfig(
            key: 'paypal',
            driver: PaypalGateway::class,
            name: 'PayPal',
            enabled: $enabled,
            credentials: $credentials ?? ['client_id' => 'id', 'client_secret' => 'secret'],
        ));
    }

    private function charge(string $email): ChargeResult
    {
        return $this->gateway()->charge(new ChargeRequest(
            paymentId: 1,
            orderId: 1,
            amount: '10.00',
            currency: 'USD',
            customerName: 'Jane',
            customerEmail: 'jane@example.com',
            details: ['paypal_email' => $email],
        ));
    }

    public function test_an_ordinary_payer_is_approved(): void
    {
        $result = $this->charge('jane.buyer@example.com');

        $this->assertSame(PaymentStatus::Successful, $result->status);
        $this->assertMatchesRegularExpression('/^PAYID-[A-Z0-9]{24}$/', (string) $result->transactionId);
        $this->assertSame(['payer_email' => 'j***@example.com', 'mode' => 'sandbox'], $result->meta);
        $this->assertNull($result->failureCode);
    }

    /** @return array<string, array{0: string}> */
    public static function decliningPayers(): array
    {
        return [
            'lower case' => ['declined@example.com'],
            'prefix with suffix' => ['declined.buyer@example.com'],
            'upper case' => ['DECLINED@Example.com'],
        ];
    }

    #[DataProvider('decliningPayers')]
    public function test_a_payer_whose_email_starts_with_declined_is_refused(string $email): void
    {
        $result = $this->charge($email);

        $this->assertSame(PaymentStatus::Failed, $result->status);
        $this->assertSame('payer_declined', $result->failureCode);
        $this->assertSame('The PayPal payer declined the payment.', $result->failureMessage);
        $this->assertNull($result->transactionId);
    }

    public function test_declined_only_matches_as_a_prefix(): void
    {
        $this->assertSame(PaymentStatus::Successful, $this->charge('not-declined@example.com')->status);
    }

    public function test_the_payer_email_is_masked_in_meta(): void
    {
        $meta = $this->charge('jane.buyer@example.com')->meta;

        $this->assertStringNotContainsString('jane.buyer', (string) json_encode($meta));
    }

    /** @return array<string, array{0: bool, 1: array<string, mixed>, 2: bool}> */
    public static function availability(): array
    {
        return [
            'configured' => [true, ['client_id' => 'id', 'client_secret' => 'secret'], true],
            'disabled' => [false, ['client_id' => 'id', 'client_secret' => 'secret'], false],
            'client id blank' => [true, ['client_id' => '', 'client_secret' => 'secret'], false],
            'client secret missing' => [true, ['client_id' => 'id'], false],
        ];
    }

    /** @param array<string, mixed> $credentials */
    #[DataProvider('availability')]
    public function test_availability(bool $enabled, array $credentials, bool $expected): void
    {
        $this->assertSame($expected, $this->gateway($enabled, $credentials)->isAvailable());
    }

    public function test_paypal_email_is_required(): void
    {
        $this->assertSame(['paypal_email' => ['required', 'string', 'email', 'max:255']], $this->gateway()->rules());
    }
}
