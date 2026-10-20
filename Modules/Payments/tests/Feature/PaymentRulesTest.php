<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentGatewaySetting;
use Modules\Payments\Tests\Support\MakesPayments;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

/**
 * The rules every payment shares, whatever the gateway.
 */
class PaymentRulesTest extends TestCase
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

    /** @return array<string, array{0: string}> */
    public static function unconfirmedStatuses(): array
    {
        return ['pending' => ['pending'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('unconfirmedStatuses')]
    public function test_only_confirmed_orders_can_be_paid(string $status): void
    {
        $order = Order::factory()->withItems()->for($this->user)->create(['status' => $status]);

        $this->pay($order, $this->card())
            ->assertStatus(409)
            ->assertExactJson([
                'message' => "Payments can only be processed for confirmed orders. This order is {$status}.",
                'code' => 'order_not_confirmed',
                'context' => ['order_status' => $status],
            ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_paid_order_cannot_be_paid_again(): void
    {
        $order = $this->confirmedOrder($this->user);
        $paid = Payment::factory()->forOrder($order)->create();

        $this->pay($order, $this->paypal())
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'This order has already been paid.',
                'code' => 'order_already_paid',
                'context' => ['payment_id' => $paid->id],
            ]);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_pending_payment_blocks_a_new_one(): void
    {
        $order = $this->confirmedOrder($this->user);
        Payment::factory()->forOrder($order)->pending()->create();

        $this->pay($order, $this->card())
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_in_progress');
    }

    public function test_failed_payments_do_not_block_a_retry(): void
    {
        $order = $this->confirmedOrder($this->user);
        Payment::factory()->forOrder($order)->failed()->count(3)->create();

        $this->pay($order, $this->card())->assertCreated();
    }

    public function test_someone_elses_order_cannot_be_paid(): void
    {
        $foreign = $this->confirmedOrder();

        $this->pay($foreign, $this->card())
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_missing_order_is_404(): void
    {
        $this->postJson('/api/v1/orders/999999/payments', $this->card())->assertNotFound();
    }

    public function test_method_is_required(): void
    {
        $this->pay($this->confirmedOrder($this->user), ['details' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method' => 'The method field is required.']);
    }

    public function test_an_unknown_method_lists_the_available_ones(): void
    {
        $this->pay($this->confirmedOrder($this->user), ['method' => 'bitcoin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'method' => 'The selected payment method is not available. Available methods: credit_card, paypal, bank_transfer.',
            ]);
    }

    public function test_a_gateway_disabled_in_config_cannot_be_used(): void
    {
        config(['payments.gateways.paypal.enabled' => false]);

        $this->pay($this->confirmedOrder($this->user), $this->paypal())
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'method' => 'The selected payment method is not available. Available methods: credit_card, bank_transfer.',
            ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_gateway_missing_a_credential_cannot_be_used(): void
    {
        config(['payments.gateways.credit_card.credentials.secret_key' => null]);

        $this->pay($this->confirmedOrder($this->user), $this->card())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);
    }

    public function test_a_gateway_disabled_by_a_database_override_cannot_be_used(): void
    {
        PaymentGatewaySetting::query()->create(['key' => 'credit_card', 'enabled' => false]);

        $this->pay($this->confirmedOrder($this->user), $this->card())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->pay($this->confirmedOrder($this->user), $this->paypal())->assertCreated();
    }

    public function test_details_must_be_an_object(): void
    {
        $this->pay($this->confirmedOrder($this->user), ['method' => 'bank_transfer', 'details' => 'card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details']);
    }

    public function test_every_payment_endpoint_requires_a_token(): void
    {
        $order = $this->confirmedOrder($this->user);
        $payment = Payment::factory()->forOrder($order)->create();

        $this->withoutToken();

        $this->pay($order, $this->card())->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/orders/{$order->id}/payments")->assertUnauthorized();
        $this->getJson('/api/v1/payments')->assertUnauthorized();
        $this->getJson("/api/v1/payments/{$payment->id}")->assertUnauthorized();
        $this->getJson('/api/v1/payment-gateways')->assertUnauthorized();

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_revoked_token_cannot_take_payment(): void
    {
        $order = $this->confirmedOrder($this->user);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->pay($order, $this->card())->assertUnauthorized();
        $this->assertDatabaseCount('payments', 0);
    }
}
