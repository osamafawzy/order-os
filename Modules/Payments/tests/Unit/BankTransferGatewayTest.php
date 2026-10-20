<?php

namespace Modules\Payments\Tests\Unit;

use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Gateways\BankTransferGateway;
use Modules\Payments\Support\GatewayConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankTransferGatewayTest extends TestCase
{
    /** @param array<string, mixed> $settings */
    private function gateway(bool $enabled = true, ?array $settings = null): BankTransferGateway
    {
        return new BankTransferGateway(new GatewayConfig(
            key: 'bank_transfer',
            driver: BankTransferGateway::class,
            name: 'Bank Transfer',
            enabled: $enabled,
            settings: $settings ?? [
                'beneficiary' => 'Order OS Ltd.',
                'bank_name' => 'Example Bank',
                'iban' => 'GB82WEST12345698765432',
            ],
        ));
    }

    public function test_a_transfer_is_left_pending_with_instructions_to_pay(): void
    {
        $result = $this->gateway()->charge(new ChargeRequest(
            paymentId: 9,
            orderId: 5,
            amount: '60.99',
            currency: 'EUR',
            customerName: 'Jane',
            customerEmail: 'jane@example.com',
        ));

        $this->assertSame(PaymentStatus::Pending, $result->status);
        $this->assertSame('ORD-5-PAY-9', $result->transactionId);
        $this->assertNull($result->failureCode);
        $this->assertSame([
            'instructions' => [
                'beneficiary' => 'Order OS Ltd.',
                'bank_name' => 'Example Bank',
                'iban' => 'GB82WEST12345698765432',
                'amount' => '60.99',
                'currency' => 'EUR',
                'reference' => 'ORD-5-PAY-9',
            ],
        ], $result->meta);
    }

    public function test_the_account_holder_is_kept_in_meta_when_sent(): void
    {
        $result = $this->gateway()->charge(new ChargeRequest(
            paymentId: 1,
            orderId: 1,
            amount: '10.00',
            currency: 'USD',
            customerName: 'Jane',
            customerEmail: 'jane@example.com',
            details: ['account_holder' => 'Jane Q. Doe'],
        ));

        $this->assertSame('Jane Q. Doe', $result->meta['account_holder']);
        $this->assertSame('ORD-1-PAY-1', $result->meta['instructions']['reference']);
    }

    /** @return array<string, array{0: bool, 1: array<string, mixed>, 2: bool}> */
    public static function availability(): array
    {
        return [
            'iban configured, no credentials needed' => [true, ['iban' => 'GB82WEST12345698765432'], true],
            'disabled' => [false, ['iban' => 'GB82WEST12345698765432'], false],
            'iban missing' => [true, ['beneficiary' => 'x'], false],
            'iban blank' => [true, ['iban' => ''], false],
        ];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('availability')]
    public function test_availability_depends_on_the_iban_setting(bool $enabled, array $settings, bool $expected): void
    {
        $this->assertSame($expected, $this->gateway($enabled, $settings)->isAvailable());
    }

    public function test_no_details_are_required(): void
    {
        foreach ($this->gateway()->rules() as $rules) {
            $this->assertNotContains('required', $rules);
        }
    }
}
