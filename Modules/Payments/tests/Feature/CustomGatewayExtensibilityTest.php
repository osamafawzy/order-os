<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Models\Payment;
use Modules\Payments\Tests\Support\FakeGateway;
use Modules\Payments\Tests\Support\MakesPayments;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

/**
 * The extensibility claim, end to end: a gateway class that lives only in
 * the test suite, registered with nothing but a config entry, works through
 * the real HTTP API — listing, validation, charging, declines, filtering and
 * the admin command — with no other change anywhere.
 */
class CustomGatewayExtensibilityTest extends TestCase
{
    use InteractsWithJwt;
    use MakesPayments;
    use RefreshDatabase;

    private User $user;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGateway::$charges = [];

        config(['payments.gateways.fake' => [
            'driver' => FakeGateway::class,
            'name' => 'Fake Pay',
            'enabled' => true,
            'credentials' => ['api_key' => 'fake_live_key'],
        ]]);

        $this->user = $this->actingAsJwt();
        $this->order = $this->confirmedOrder($this->user);
    }

    public function test_the_new_gateway_is_listed_with_fields_from_its_own_rules(): void
    {
        $this->getJson('/api/v1/payment-gateways')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.3', [
                'key' => 'fake',
                'name' => 'Fake Pay',
                'available' => true,
                'fields' => [
                    ['name' => 'token', 'required' => true],
                    ['name' => 'memo', 'required' => false],
                ],
            ]);
    }

    public function test_a_payment_through_the_new_gateway_succeeds(): void
    {
        $response = $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => 'tok_ok', 'memo' => 'hi']]);

        $payment = Payment::query()->sole();

        $response->assertCreated()
            ->assertJsonPath('data.method', 'fake')
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.transaction_id', 'fake_'.$payment->id)
            ->assertJsonPath('data.details', ['currency_seen' => 'USD', 'app_name' => config('app.name')]);

        $this->assertCount(1, FakeGateway::$charges);
        $charge = FakeGateway::$charges[0];
        $this->assertSame('60.99', $charge->amount);
        $this->assertSame($payment->id, $charge->paymentId);
        $this->assertSame(['token' => 'tok_ok', 'memo' => 'hi'], $charge->details);
    }

    public function test_a_decline_from_the_new_gateway_is_402(): void
    {
        $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => FakeGateway::DECLINE_TOKEN]])
            ->assertStatus(402)
            ->assertJsonPath('data.failure.code', 'fake_declined');
    }

    public function test_the_new_gateways_rules_validate_the_request_with_readable_names(): void
    {
        $this->pay($this->order, ['method' => 'fake'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details.token' => 'The token field is required.']);

        $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => 'nope']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details.token' => 'The token field must start with one of the following: tok_.']);

        $this->assertSame([], FakeGateway::$charges);
    }

    public function test_the_shared_payment_rules_apply_to_the_new_gateway_too(): void
    {
        $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => 'tok_ok']])->assertCreated();

        $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => 'tok_ok']])
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_already_paid');

        $pending = Order::factory()->withItems()->for($this->user)->create();
        $this->pay($pending, ['method' => 'fake', 'details' => ['token' => 'tok_ok']])
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_not_confirmed');

        $this->assertCount(1, FakeGateway::$charges);
    }

    public function test_payments_can_be_filtered_by_the_new_method(): void
    {
        $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => 'tok_ok']])->assertCreated();
        $this->pay($this->confirmedOrder($this->user), $this->card())->assertCreated();

        $this->getJson('/api/v1/payments?method=fake')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.method', 'fake');
    }

    public function test_the_new_gateway_is_managed_by_the_artisan_command(): void
    {
        $this->artisan('payments:gateway', ['key' => 'fake', '--disable' => true])->assertSuccessful();

        $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => 'tok_ok']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->artisan('payments:gateway', ['key' => 'fake', '--enable' => true, '--credential' => ['api_key=rotated']])->assertSuccessful();

        $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => 'tok_ok']])->assertCreated();
    }

    public function test_without_its_required_credential_the_new_gateway_is_unavailable(): void
    {
        config(['payments.gateways.fake.credentials.api_key' => '']);

        $this->getJson('/api/v1/payment-gateways')->assertJsonPath('data.3.available', false);
        $this->pay($this->order, ['method' => 'fake', 'details' => ['token' => 'tok_ok']])->assertUnprocessable();
    }
}
