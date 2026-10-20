<?php

namespace Modules\Payments\Tests\Unit;

use LogicException;
use Mockery;
use Modules\Payments\Exceptions\PaymentException;
use Modules\Payments\Gateways\BankTransferGateway;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Gateways\PaypalGateway;
use Modules\Payments\Repositories\Contracts\PaymentGatewaySettingRepositoryInterface;
use Modules\Payments\Services\GatewayConfigResolver;
use Modules\Payments\Services\GatewayRegistry;
use Modules\Payments\Tests\Support\FakeGateway;
use stdClass;
use Tests\TestCase;

/**
 * The strategy selector, isolated from the database: the settings
 * repository is a mock with no overrides, so config is the only input.
 */
class GatewayRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.gateways' => [
            'credit_card' => [
                'driver' => CreditCardGateway::class,
                'name' => 'Credit Card',
                'enabled' => true,
                'credentials' => ['public_key' => 'pk', 'secret_key' => 'sk'],
            ],
            'paypal' => [
                'driver' => PaypalGateway::class,
                'name' => 'PayPal',
                'enabled' => false,
                'credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
            ],
            'bank_transfer' => [
                'driver' => BankTransferGateway::class,
                'name' => 'Bank Transfer',
                'enabled' => true,
                // Enabled but missing its required IBAN.
                'settings' => ['iban' => null],
            ],
        ]]);
    }

    private function registry(): GatewayRegistry
    {
        $settings = Mockery::mock(PaymentGatewaySettingRepositoryInterface::class);
        $settings->shouldReceive('allKeyed')->andReturn([]);

        return new GatewayRegistry($this->app, new GatewayConfigResolver($settings));
    }

    public function test_it_builds_the_configured_driver_with_its_own_config(): void
    {
        $gateway = $this->registry()->get('credit_card');

        $this->assertInstanceOf(CreditCardGateway::class, $gateway);
        $this->assertSame('credit_card', $gateway->key());
        $this->assertSame('Credit Card', $gateway->name());
        $this->assertTrue($gateway->isAvailable());
    }

    public function test_each_gateway_is_built_once_per_registry(): void
    {
        $registry = $this->registry();

        $this->assertSame($registry->get('credit_card'), $registry->get('credit_card'));
    }

    public function test_an_unknown_key_is_an_unsupported_payment_method(): void
    {
        try {
            $this->registry()->get('bitcoin');
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException $e) {
            $this->assertSame('unsupported_payment_method', $e->errorCode());
            $this->assertSame(422, $e->status());
            $this->assertSame('The payment method "bitcoin" is not supported.', $e->getMessage());
            $this->assertSame(['available_methods' => ['credit_card']], $e->context());
        }
    }

    public function test_a_driver_that_does_not_implement_the_contract_is_a_logic_error(): void
    {
        config(['payments.gateways.broken' => ['driver' => stdClass::class, 'name' => 'Broken', 'enabled' => true]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment gateway [broken] is configured with driver [stdClass], which does not implement');

        $this->registry()->get('broken');
    }

    public function test_available_returns_a_usable_gateway(): void
    {
        $this->assertInstanceOf(CreditCardGateway::class, $this->registry()->available('credit_card'));
    }

    public function test_available_refuses_a_disabled_gateway(): void
    {
        try {
            $this->registry()->available('paypal');
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException $e) {
            $this->assertSame('payment_method_unavailable', $e->errorCode());
            $this->assertSame(422, $e->status());
            $this->assertSame(['available_methods' => ['credit_card']], $e->context());
        }
    }

    public function test_available_refuses_an_enabled_gateway_missing_a_required_setting(): void
    {
        $this->expectException(PaymentException::class);

        $this->registry()->available('bank_transfer');
    }

    public function test_available_on_an_unknown_key_is_unsupported_not_unavailable(): void
    {
        try {
            $this->registry()->available('bitcoin');
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException $e) {
            $this->assertSame('unsupported_payment_method', $e->errorCode());
        }
    }

    public function test_all_lists_every_configured_gateway_and_available_keys_only_usable_ones(): void
    {
        $registry = $this->registry();

        $this->assertSame(['credit_card', 'paypal', 'bank_transfer'], array_keys($registry->all()));
        $this->assertSame(['credit_card'], $registry->availableKeys());
        $this->assertTrue($registry->has('paypal'));
        $this->assertFalse($registry->has('bitcoin'));
    }

    public function test_entries_without_a_driver_are_ignored(): void
    {
        config(['payments.gateways.half_written' => ['name' => 'No driver yet', 'enabled' => true]]);
        config(['payments.gateways.not_an_array' => 'oops']);

        $registry = $this->registry();

        $this->assertFalse($registry->has('half_written'));
        $this->assertFalse($registry->has('not_an_array'));
        $this->assertCount(3, $registry->all());
    }

    public function test_a_new_gateway_needs_only_a_config_entry(): void
    {
        config(['payments.gateways.fake' => [
            'driver' => FakeGateway::class,
            'name' => 'Fake Pay',
            'enabled' => true,
            'credentials' => ['api_key' => 'fake_key'],
        ]]);

        $registry = $this->registry();
        $gateway = $registry->available('fake');

        // Built by the container: its extra constructor dependency was autowired.
        $this->assertInstanceOf(FakeGateway::class, $gateway);
        $this->assertSame('Fake Pay', $gateway->name());
        $this->assertSame(['credit_card', 'fake'], $registry->availableKeys());
    }
}
