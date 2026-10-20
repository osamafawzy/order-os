<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Events\PaymentFailed;
use Modules\Payments\Events\PaymentSucceeded;
use Modules\Payments\Exceptions\GatewayUnreachable;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Models\Payment;
use Modules\Payments\Tests\Support\ExplodingGateway;
use Modules\Payments\Tests\Support\MakesPayments;
use Modules\Payments\Tests\Support\TimingOutGateway;
use RuntimeException;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

/**
 * The vendor could not be asked (unreachable: failed, safe to retry), or was
 * asked and never answered (unknown outcome: held pending, never retried).
 */
class GatewayErrorTest extends TestCase
{
    use InteractsWithJwt;
    use MakesPayments;
    use RefreshDatabase;

    private User $user;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // The card gateway's config, rules and credentials, but a driver
        // whose every charge throws.
        config(['payments.gateways.credit_card.driver' => ExplodingGateway::class]);

        $this->user = $this->actingAsJwt();
        $this->order = $this->confirmedOrder($this->user);
    }

    public function test_an_unreachable_gateway_is_502_and_the_payment_is_recorded_as_failed(): void
    {
        Exceptions::fake();

        $response = $this->pay($this->order, $this->paypal());
        $response->assertCreated();

        $response = $this->pay($this->confirmedOrder($this->user), $this->card());

        $payment = Payment::query()->where('method', 'credit_card')->sole();

        $response->assertStatus(502)->assertExactJson([
            'message' => 'The payment provider could not be reached. No money was taken; please try again.',
            'code' => 'gateway_unreachable',
            'context' => ['payment_id' => $payment->id, 'payment_status' => 'failed'],
        ]);

        $this->assertSame('failed', $payment->status->value);
        $this->assertSame('gateway_unreachable', $payment->failure_code);
        $this->assertNotNull($payment->processed_at);
        $this->assertNull($payment->transaction_id);

        Exceptions::assertReported(fn (GatewayUnreachable $e): bool => $e->getMessage() === 'Connection to the payment provider was refused.');
    }

    public function test_the_order_can_be_retried_once_the_gateway_recovers(): void
    {
        $this->pay($this->order, $this->card())->assertStatus(502);

        config(['payments.gateways.credit_card.driver' => CreditCardGateway::class]);

        $this->pay($this->order, $this->card())->assertCreated()->assertJsonPath('data.status', 'successful');
        $this->assertSame(2, Payment::query()->where('order_id', $this->order->id)->count());
    }

    /**
     * A decline is an answer worth replaying; "could not ask" is not. The
     * key is released, so the same-key retry a client makes after a 502 is
     * a fresh attempt rather than the error played back.
     */
    public function test_a_same_key_retry_after_an_unreachable_gateway_is_a_fresh_attempt(): void
    {
        $headers = ['Idempotency-Key' => 'retry-after-502'];

        $this->pay($this->order, $this->card(), $headers)->assertStatus(502);
        $this->assertNull(Payment::query()->sole()->idempotency_key);

        config(['payments.gateways.credit_card.driver' => CreditCardGateway::class]);

        $this->pay($this->order, $this->card(), $headers)
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertJsonPath('data.status', 'successful');

        // And from here the key does replay: it now names a real answer.
        $this->pay($this->order, $this->card(), $headers)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_a_gateway_error_attempt_is_kept_in_history_and_blocks_deletion(): void
    {
        $this->pay($this->order, $this->card())->assertStatus(502);

        // The failed attempt is history the gateway may hold: it is listed,
        // and it blocks deletion like any other payment.
        $this->getJson("/api/v1/orders/{$this->order->id}/payments")
            ->assertOk()
            ->assertJsonPath('data.0.failure.code', 'gateway_unreachable');

        $this->deleteJson("/api/v1/orders/{$this->order->id}")->assertStatus(409);
    }

    public function test_an_unreachable_gateway_dispatches_payment_failed(): void
    {
        Event::fake([PaymentFailed::class, PaymentSucceeded::class]);

        $this->pay($this->order, $this->card())->assertStatus(502);

        Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $e): bool => $e->payment->failure_code === 'gateway_unreachable');
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_a_timeout_is_502_and_the_payment_is_held_pending(): void
    {
        Exceptions::fake();
        Event::fake([PaymentFailed::class, PaymentSucceeded::class]);
        config(['payments.gateways.credit_card.driver' => TimingOutGateway::class]);

        $response = $this->pay($this->order, $this->card(), ['Idempotency-Key' => 'timeout-key-1']);

        $payment = Payment::query()->sole();

        $response->assertStatus(502)->assertExactJson([
            'message' => 'The payment provider did not confirm the outcome. The payment is pending until it is reconciled; do not pay again.',
            'code' => 'gateway_error',
            'context' => ['payment_id' => $payment->id, 'payment_status' => 'pending'],
        ]);

        $this->assertSame('pending', $payment->status->value);
        $this->assertSame('gateway_error', $payment->failure_code);
        $this->assertNull($payment->processed_at);
        $this->assertSame('timeout-key-1', $payment->idempotency_key);

        Exceptions::assertReported(RuntimeException::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /**
     * The vendor may already have the money, so nothing may charge again
     * until reconciliation knows: not a new attempt, not a cancel — and the
     * client's same-key retry is answered with the held payment.
     */
    public function test_a_timed_out_payment_blocks_retries_and_cancellation(): void
    {
        config(['payments.gateways.credit_card.driver' => TimingOutGateway::class]);

        $this->pay($this->order, $this->card(), ['Idempotency-Key' => 'timeout-key-2'])->assertStatus(502);

        config(['payments.gateways.credit_card.driver' => CreditCardGateway::class]);

        $this->pay($this->order, $this->card())
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_in_progress');

        $this->pay($this->order, $this->card(), ['Idempotency-Key' => 'timeout-key-2'])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.failure.code', 'gateway_error');

        $this->patchJson("/api/v1/orders/{$this->order->id}", ['status' => 'cancelled'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'payment_in_progress');

        $this->assertSame(1, Payment::query()->count());
    }
}
