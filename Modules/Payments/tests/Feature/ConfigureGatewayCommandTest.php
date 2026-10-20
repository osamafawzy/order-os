<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Payments\Models\PaymentGatewaySetting;
use Modules\Payments\Services\GatewayConfigResolver;
use Modules\Payments\Support\GatewayConfig;
use Modules\Payments\Tests\Support\MakesPayments;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class ConfigureGatewayCommandTest extends TestCase
{
    use InteractsWithJwt;
    use MakesPayments;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
    }

    private function setting(string $key): ?PaymentGatewaySetting
    {
        return PaymentGatewaySetting::query()->where('key', $key)->first();
    }

    private function resolved(string $key): ?GatewayConfig
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(GatewayConfigResolver::class)->find($key);
    }

    public function test_disable_switches_a_gateway_off_for_the_api(): void
    {
        $this->artisan('payments:gateway', ['key' => 'paypal', '--disable' => true])
            ->expectsOutput('Gateway [paypal] is now disabled.')
            ->assertSuccessful();

        $this->assertFalse($this->setting('paypal')?->enabled);

        $this->getJson('/api/v1/payment-gateways')->assertJsonPath('data.1.available', false);
        $this->pay($this->confirmedOrder($this->user), $this->paypal())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);
    }

    public function test_enable_switches_on_a_gateway_that_config_has_off(): void
    {
        config(['payments.gateways.paypal.enabled' => false]);

        $this->artisan('payments:gateway', ['key' => 'paypal', '--enable' => true])
            ->expectsOutput('Gateway [paypal] is now enabled.')
            ->assertSuccessful();

        $this->pay($this->confirmedOrder($this->user), $this->paypal())->assertCreated();
    }

    public function test_credentials_are_stored_encrypted_and_merged_with_earlier_ones(): void
    {
        $this->artisan('payments:gateway', ['key' => 'credit_card', '--credential' => ['secret_key=sk_live_rotated']])->assertSuccessful();
        $this->artisan('payments:gateway', ['key' => 'credit_card', '--credential' => ['public_key=pk_live_rotated']])->assertSuccessful();

        $this->assertSame(
            ['secret_key' => 'sk_live_rotated', 'public_key' => 'pk_live_rotated'],
            $this->setting('credit_card')?->credentials,
        );

        $raw = (string) DB::table('payment_gateway_settings')->where('key', 'credit_card')->value('credentials');
        $this->assertStringNotContainsString('sk_live_rotated', $raw);
        $this->assertStringNotContainsString('pk_live_rotated', $raw);

        $this->assertSame('sk_live_rotated', $this->resolved('credit_card')?->credential('secret_key'));
    }

    public function test_a_credential_change_alone_leaves_the_switch_to_config(): void
    {
        $this->artisan('payments:gateway', ['key' => 'paypal', '--credential' => ['client_secret=new_secret']])
            ->expectsOutput('Gateway [paypal] is now enabled.')
            ->assertSuccessful();

        $this->assertNull($this->setting('paypal')?->enabled);
        $this->assertSame('paypal_test_client', $this->resolved('paypal')?->credential('client_id'));
        $this->assertSame('new_secret', $this->resolved('paypal')?->credential('client_secret'));
    }

    public function test_a_value_may_itself_contain_an_equals_sign(): void
    {
        $this->artisan('payments:gateway', ['key' => 'credit_card', '--credential' => ['secret_key=sk_abc=='], '--setting' => ['mode=live']])
            ->assertSuccessful();

        $this->assertSame('sk_abc==', $this->resolved('credit_card')?->credential('secret_key'));
        $this->assertSame('live', $this->resolved('credit_card')?->setting('mode'));
    }

    public function test_blanking_a_required_credential_makes_the_gateway_unavailable(): void
    {
        $this->artisan('payments:gateway', ['key' => 'credit_card', '--credential' => ['secret_key=']])->assertSuccessful();

        $this->pay($this->confirmedOrder($this->user), $this->card())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);
    }

    public function test_a_setting_change_reaches_the_gateway(): void
    {
        $this->artisan('payments:gateway', ['key' => 'bank_transfer', '--setting' => ['iban=DE89370400440532013000']])->assertSuccessful();

        $this->pay($this->confirmedOrder($this->user), $this->bankTransfer())
            ->assertCreated()
            ->assertJsonPath('data.details.instructions.iban', 'DE89370400440532013000')
            ->assertJsonPath('data.details.instructions.bank_name', 'Example Bank');
    }

    public function test_reset_removes_the_override_so_config_applies_again(): void
    {
        $this->artisan('payments:gateway', ['key' => 'paypal', '--disable' => true, '--credential' => ['client_id=db_id']])->assertSuccessful();

        $this->artisan('payments:gateway', ['key' => 'paypal', '--reset' => true])
            ->expectsOutput('Gateway [paypal] override removed; config/.env applies (enabled).')
            ->assertSuccessful();

        $this->assertNull($this->setting('paypal'));
        $this->assertSame('paypal_test_client', $this->resolved('paypal')?->credential('client_id'));
        $this->pay($this->confirmedOrder($this->user), $this->paypal())->assertCreated();
    }

    public function test_reset_without_an_override_is_harmless(): void
    {
        $this->artisan('payments:gateway', ['key' => 'credit_card', '--reset' => true])->assertSuccessful();

        $this->assertDatabaseCount('payment_gateway_settings', 0);
    }

    public function test_an_unknown_gateway_is_refused(): void
    {
        $this->artisan('payments:gateway', ['key' => 'bitcoin', '--enable' => true])
            ->expectsOutput('No gateway is configured under [bitcoin]. Known: credit_card, paypal, bank_transfer')
            ->assertFailed();

        $this->assertDatabaseCount('payment_gateway_settings', 0);
    }

    public function test_enable_and_disable_together_are_refused(): void
    {
        $this->artisan('payments:gateway', ['key' => 'paypal', '--enable' => true, '--disable' => true])
            ->expectsOutput('Pass --enable or --disable, not both.')
            ->assertFailed();

        $this->assertDatabaseCount('payment_gateway_settings', 0);
    }
}
