<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payments\Models\PaymentGatewaySetting;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class PaymentGatewaysEndpointTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsJwt();
    }

    public function test_it_lists_every_gateway_with_its_fields(): void
    {
        $this->getJson('/api/v1/payment-gateways')
            ->assertOk()
            ->assertExactJson(['data' => [
                [
                    'key' => 'credit_card',
                    'name' => 'Credit Card',
                    'available' => true,
                    'fields' => [
                        ['name' => 'card_number', 'required' => true],
                        ['name' => 'exp_month', 'required' => true],
                        ['name' => 'exp_year', 'required' => true],
                        ['name' => 'cvc', 'required' => true],
                        ['name' => 'holder_name', 'required' => false],
                    ],
                ],
                [
                    'key' => 'paypal',
                    'name' => 'PayPal',
                    'available' => true,
                    'fields' => [['name' => 'paypal_email', 'required' => true]],
                ],
                [
                    'key' => 'bank_transfer',
                    'name' => 'Bank Transfer',
                    'available' => true,
                    'fields' => [['name' => 'account_holder', 'required' => false]],
                ],
            ]]);
    }

    public function test_unavailable_gateways_are_listed_as_such(): void
    {
        config(['payments.gateways.paypal.enabled' => false]);
        config(['payments.gateways.bank_transfer.settings.iban' => null]);

        $this->getJson('/api/v1/payment-gateways')
            ->assertOk()
            ->assertJsonPath('data.0.available', true)
            ->assertJsonPath('data.1.available', false)
            ->assertJsonPath('data.2.available', false);
    }

    public function test_a_database_override_is_reflected(): void
    {
        PaymentGatewaySetting::query()->create(['key' => 'credit_card', 'enabled' => false]);

        $this->getJson('/api/v1/payment-gateways')->assertJsonPath('data.0.available', false);
    }

    public function test_no_credential_or_setting_is_ever_exposed(): void
    {
        PaymentGatewaySetting::query()->create([
            'key' => 'credit_card',
            'credentials' => ['secret_key' => 'sk_live_from_database'],
        ]);

        $body = (string) $this->getJson('/api/v1/payment-gateways')->assertOk()->getContent();

        foreach (['pk_test_suite', 'sk_test_suite', 'sk_live_from_database', 'paypal_test_client', 'paypal_test_secret', 'GB82WEST12345698765432', 'sandbox', 'credentials', 'settings'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
