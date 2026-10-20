<?php

namespace Modules\Payments\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Models\PaymentGatewaySetting;
use Modules\Payments\Repositories\Contracts\PaymentGatewaySettingRepositoryInterface;
use Modules\Payments\Services\GatewayConfigResolver;
use Tests\TestCase;

/**
 * config/.env is the seed; a payment_gateway_settings row overrides it.
 */
class GatewayConfigResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.gateways' => [
            'credit_card' => [
                'driver' => CreditCardGateway::class,
                'name' => 'Credit Card',
                'enabled' => true,
                'credentials' => ['public_key' => 'pk_env', 'secret_key' => 'sk_env'],
                'settings' => ['mode' => 'sandbox'],
            ],
        ]]);
    }

    private function resolver(): GatewayConfigResolver
    {
        return new GatewayConfigResolver($this->app->make(PaymentGatewaySettingRepositoryInterface::class));
    }

    /** @param array<string, mixed> $attributes */
    private function override(array $attributes): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::query()->create(['key' => 'credit_card'] + $attributes);
    }

    public function test_without_a_row_config_is_used_as_is(): void
    {
        $config = $this->resolver()->find('credit_card');

        $this->assertNotNull($config);
        $this->assertSame('credit_card', $config->key);
        $this->assertSame(CreditCardGateway::class, $config->driver);
        $this->assertSame('Credit Card', $config->name);
        $this->assertTrue($config->enabled);
        $this->assertSame(['public_key' => 'pk_env', 'secret_key' => 'sk_env'], $config->credentials);
        $this->assertSame('sandbox', $config->setting('mode'));
    }

    public function test_a_row_can_switch_an_enabled_gateway_off(): void
    {
        $this->override(['enabled' => false]);

        $this->assertFalse($this->resolver()->find('credit_card')?->enabled);
    }

    public function test_a_row_can_switch_a_disabled_gateway_on(): void
    {
        config(['payments.gateways.credit_card.enabled' => false]);
        $this->override(['enabled' => true]);

        $this->assertTrue($this->resolver()->find('credit_card')?->enabled);
    }

    public function test_a_row_with_a_null_enabled_leaves_the_config_switch_alone(): void
    {
        config(['payments.gateways.credit_card.enabled' => false]);
        $this->override(['enabled' => null, 'settings' => ['mode' => 'live']]);

        $config = $this->resolver()->find('credit_card');

        $this->assertFalse($config?->enabled);
        $this->assertSame('live', $config?->setting('mode'));
    }

    public function test_credentials_are_merged_per_key(): void
    {
        // Rotating only the secret keeps the public key from .env.
        $this->override(['credentials' => ['secret_key' => 'sk_rotated']]);

        $this->assertSame(
            ['public_key' => 'pk_env', 'secret_key' => 'sk_rotated'],
            $this->resolver()->find('credit_card')?->credentials,
        );
    }

    public function test_settings_are_merged_per_key(): void
    {
        $this->override(['settings' => ['mode' => 'live', 'statement_descriptor' => 'ORDER OS']]);

        $this->assertSame(
            ['mode' => 'live', 'statement_descriptor' => 'ORDER OS'],
            $this->resolver()->find('credit_card')?->settings,
        );
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->override(['credentials' => ['secret_key' => 'sk_live_super_secret']]);

        $raw = (string) DB::table('payment_gateway_settings')->where('key', 'credit_card')->value('credentials');

        $this->assertStringNotContainsString('sk_live_super_secret', $raw);
        $this->assertSame(['secret_key' => 'sk_live_super_secret'], json_decode(Crypt::decryptString($raw), true));
    }

    public function test_credentials_are_hidden_when_a_setting_is_serialised(): void
    {
        $setting = $this->override(['credentials' => ['secret_key' => 'sk_live_super_secret'], 'enabled' => true]);

        $this->assertArrayNotHasKey('credentials', $setting->toArray());
        $this->assertStringNotContainsString('sk_live_super_secret', $setting->toJson());
    }

    public function test_a_row_cannot_introduce_a_gateway_that_is_not_in_config(): void
    {
        // The driver class can only come from config: a row naming a new key
        // must not make anything executable.
        PaymentGatewaySetting::query()->create(['key' => 'evil', 'enabled' => true, 'settings' => ['driver' => 'App\\Evil']]);

        $resolver = $this->resolver();

        $this->assertNull($resolver->find('evil'));
        $this->assertSame(['credit_card'], array_keys($resolver->all()));
    }

    public function test_results_are_cached_until_flushed(): void
    {
        $resolver = $this->resolver();
        $this->assertTrue($resolver->find('credit_card')?->enabled);

        $this->override(['enabled' => false]);
        $this->assertTrue($resolver->find('credit_card')?->enabled, 'Resolved once per request.');

        $resolver->flush();
        $this->assertFalse($resolver->find('credit_card')?->enabled);
    }

    public function test_it_falls_back_to_config_when_the_settings_table_does_not_exist(): void
    {
        Schema::drop('payment_gateway_settings');

        $this->assertTrue($this->resolver()->find('credit_card')?->enabled);
    }

    public function test_an_unknown_key_resolves_to_null(): void
    {
        $this->assertNull($this->resolver()->find('nope'));
    }
}
