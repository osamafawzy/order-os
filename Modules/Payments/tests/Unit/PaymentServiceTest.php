<?php

namespace Modules\Payments\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\DTOs\ProcessPaymentData;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentFailed;
use Modules\Payments\Events\PaymentProcessed;
use Modules\Payments\Events\PaymentSucceeded;
use Modules\Payments\Exceptions\GatewayUnreachable;
use Modules\Payments\Exceptions\PaymentException;
use Modules\Payments\Models\Payment;
use Modules\Payments\Repositories\Contracts\PaymentRepositoryInterface;
use Modules\Payments\Repositories\PaymentRepository;
use Modules\Payments\Services\GatewayRegistry;
use Modules\Payments\Services\PaymentService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PaymentService against a scripted gateway: what it writes, when, and what
 * it does with each kind of answer.
 */
class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    /** What the gateway saw in the database at the moment it was called. */
    private ?Payment $rowDuringCharge = null;

    private ?ChargeRequest $request = null;

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PaymentProcessed::class, PaymentSucceeded::class, PaymentFailed::class]);
    }

    /**
     * A gateway whose charge() runs the given callback, recording what it
     * was asked and what the payments table held at that instant.
     *
     * @param  callable(ChargeRequest): ChargeResult  $answer
     */
    private function service(callable $answer): PaymentService
    {
        $test = $this;

        $gateway = new class($answer, $test) implements PaymentGateway
        {
            /** @var callable(ChargeRequest): ChargeResult */
            private $answer;

            public function __construct(callable $answer, private readonly PaymentServiceTest $test)
            {
                $this->answer = $answer;
            }

            public function key(): string
            {
                return 'scripted';
            }

            public function name(): string
            {
                return 'Scripted';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function rules(): array
            {
                return [];
            }

            public function charge(ChargeRequest $request): ChargeResult
            {
                $this->test->recordCharge($request);

                return ($this->answer)($request);
            }
        };

        $registry = Mockery::mock(GatewayRegistry::class);
        $registry->shouldReceive('available')->with('scripted')->andReturn($gateway);

        $this->app->instance(GatewayRegistry::class, $registry);

        return $this->app->make(PaymentService::class);
    }

    /** @internal called by the scripted gateway */
    public function recordCharge(ChargeRequest $request): void
    {
        $this->calls++;
        $this->request = $request;
        $this->rowDuringCharge = Payment::query()->findOrFail($request->paymentId);
    }

    private function order(OrderStatus $status = OrderStatus::Confirmed): Order
    {
        return Order::factory()->withItems()->create(['status' => $status])->refresh();
    }

    private function data(?string $key = null): ProcessPaymentData
    {
        return new ProcessPaymentData('scripted', ['token' => 'abc'], $key);
    }

    public function test_a_pending_row_is_written_before_the_gateway_is_called(): void
    {
        $order = $this->order();

        $processed = $this->service(fn (): ChargeResult => ChargeResult::successful('tx_1'))->process($order, $this->data('key-12345678'));

        $this->assertNotNull($this->rowDuringCharge);
        $this->assertSame(PaymentStatus::Pending, $this->rowDuringCharge->status);
        $this->assertSame('60.99', $this->rowDuringCharge->amount);
        $this->assertSame('key-12345678', $this->rowDuringCharge->idempotency_key);
        $this->assertSame($processed->payment->id, $this->rowDuringCharge->id);
    }

    public function test_the_gateway_is_asked_for_the_order_total_not_a_client_figure(): void
    {
        $order = $this->order();

        $this->service(fn (): ChargeResult => ChargeResult::successful('tx_1'))->process($order, $this->data());

        $this->assertNotNull($this->request);
        $this->assertSame('60.99', $this->request->amount);
        $this->assertSame($order->currency, $this->request->currency);
        $this->assertSame($order->id, $this->request->orderId);
        $this->assertSame($order->customer_email, $this->request->customerEmail);
        $this->assertSame(['token' => 'abc'], $this->request->details);
        $this->assertSame('Order #'.$order->id, $this->request->description());
    }

    public function test_a_successful_charge_is_recorded_with_its_transaction_and_meta(): void
    {
        $order = $this->order();

        $processed = $this->service(fn (): ChargeResult => ChargeResult::successful('tx_ok', ['card_last4' => '4242']))
            ->process($order, $this->data());

        $payment = $processed->payment;
        $this->assertFalse($processed->replayed);
        $this->assertSame(PaymentStatus::Successful, $payment->status);
        $this->assertSame('tx_ok', $payment->transaction_id);
        $this->assertSame(['card_last4' => '4242'], $payment->meta);
        $this->assertSame('scripted', $payment->method);
        $this->assertSame($order->user_id, $payment->user_id);
        $this->assertNotNull($payment->processed_at);
        $this->assertNull($payment->failure_code);

        Event::assertDispatched(PaymentProcessed::class, fn (PaymentProcessed $e): bool => $e->payment->is($payment));
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e): bool => $e->payment->is($payment));
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_a_decline_is_recorded_as_failed(): void
    {
        $processed = $this->service(fn (): ChargeResult => ChargeResult::failed('card_declined', 'Declined.', 'tx_no'))
            ->process($this->order(), $this->data());

        $payment = $processed->payment;
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('card_declined', $payment->failure_code);
        $this->assertSame('Declined.', $payment->failure_message);
        $this->assertSame('tx_no', $payment->transaction_id);
        $this->assertNull($payment->meta, 'Empty meta is stored as null.');
        $this->assertNotNull($payment->processed_at);

        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_a_pending_answer_stays_pending_without_a_processed_time(): void
    {
        $processed = $this->service(fn (): ChargeResult => ChargeResult::pending('ref'))->process($this->order(), $this->data());

        $this->assertSame(PaymentStatus::Pending, $processed->payment->status);
        $this->assertNull($processed->payment->processed_at);

        Event::assertDispatched(PaymentProcessed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_an_unreachable_gateway_marks_the_payment_failed_releases_the_key_and_raises_a_502(): void
    {
        $order = $this->order();

        try {
            $this->service(fn () => throw new GatewayUnreachable('connection refused'))->process($order, $this->data('key-unreachable'));
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException $e) {
            $this->assertSame('gateway_unreachable', $e->errorCode());
            $this->assertSame(502, $e->status());

            $payment = Payment::query()->sole();
            $this->assertSame(['payment_id' => $payment->id, 'payment_status' => 'failed'], $e->context());
            $this->assertSame(PaymentStatus::Failed, $payment->status);
            $this->assertSame('gateway_unreachable', $payment->failure_code);
            $this->assertSame('The payment provider could not be reached. No money was taken; please try again.', $payment->failure_message);
            $this->assertNotNull($payment->processed_at);
            $this->assertNull($payment->idempotency_key);
        }

        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /**
     * A timeout is not "nothing happened": the vendor may have captured the
     * money and failed to say so. The payment is held pending — blocking a
     * second charge — and keeps its key, until reconciliation settles it.
     */
    public function test_an_unknown_outcome_keeps_the_payment_pending_with_its_key(): void
    {
        $order = $this->order();
        $service = $this->service(fn () => throw new RuntimeException('read timed out'));

        try {
            $service->process($order, $this->data('key-timeout'));
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException $e) {
            $this->assertSame('gateway_error', $e->errorCode());
            $this->assertSame(502, $e->status());

            $payment = Payment::query()->sole();
            $this->assertSame(['payment_id' => $payment->id, 'payment_status' => 'pending'], $e->context());
            $this->assertSame(PaymentStatus::Pending, $payment->status);
            $this->assertSame('gateway_error', $payment->failure_code);
            $this->assertNull($payment->processed_at);
            $this->assertSame('key-timeout', $payment->idempotency_key);
        }

        Event::assertNotDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentSucceeded::class);

        // A new attempt cannot charge on top of it…
        try {
            $service->process($order, $this->data('key-second-try'));
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException $e) {
            $this->assertSame('payment_in_progress', $e->errorCode());
        }

        // …and the same key replays the held payment instead of charging.
        $this->assertTrue($service->process($order, $this->data('key-timeout'))->replayed);
        $this->assertSame(1, $this->calls);
    }

    /**
     * Two same-key requests both miss the first lookup. The one that gets the
     * order lock second must see the first's row as its replay — not as a
     * payment blocking it (which would be a 409 for a safe retry).
     */
    public function test_a_same_key_request_that_loses_the_race_for_the_lock_gets_the_replay(): void
    {
        $order = $this->order();
        $service = $this->service(fn (): ChargeResult => ChargeResult::pending('tx_first'));
        $winner = $service->process($order, $this->data('race-key-1'))->payment;

        // Simulate the race: the loser's pre-lock lookup ran before the
        // winner committed, so it saw nothing.
        $real = $this->app->make(PaymentRepository::class);
        $lookups = 0;
        $repository = Mockery::mock(PaymentRepositoryInterface::class);
        $repository->shouldReceive('findByIdempotencyKey')->andReturnUsing(
            function (int $userId, string $key) use ($real, &$lookups): ?Payment {
                return $lookups++ === 0 ? null : $real->findByIdempotencyKey($userId, $key);
            },
        );
        $repository->shouldReceive('blockingForOrder')->andReturnUsing(fn (int $id) => $real->blockingForOrder($id));
        $this->app->instance(PaymentRepositoryInterface::class, $repository);

        $loser = $this->app->make(PaymentService::class)->process($order, $this->data('race-key-1'));

        $this->assertTrue($loser->replayed);
        $this->assertTrue($loser->payment->is($winner));
        $this->assertSame(2, $lookups);
        $this->assertSame(1, $this->calls);
    }

    public function test_an_unreachable_gateway_does_not_block_a_retry(): void
    {
        $order = $this->order();
        $service = $this->service(function (): ChargeResult {
            if ($this->calls === 1) {
                throw new GatewayUnreachable('connection refused');
            }

            return ChargeResult::successful('tx_retry');
        });

        try {
            $service->process($order, $this->data());
        } catch (PaymentException) {
        }

        $this->assertSame(PaymentStatus::Successful, $service->process($order, $this->data())->payment->status);
        $this->assertSame(2, Payment::query()->where('order_id', $order->id)->count());
    }

    /** @return array<string, array{0: OrderStatus}> */
    public static function unpayableStatuses(): array
    {
        return [
            'pending' => [OrderStatus::Pending],
            'cancelled' => [OrderStatus::Cancelled],
        ];
    }

    #[DataProvider('unpayableStatuses')]
    public function test_an_unconfirmed_order_is_refused_before_anything_is_written_or_charged(OrderStatus $status): void
    {
        $order = $this->order($status);

        try {
            $this->service(fn (): ChargeResult => ChargeResult::successful('tx'))->process($order, $this->data());
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException $e) {
            $this->assertSame('order_not_confirmed', $e->errorCode());
            $this->assertSame(['order_status' => $status->value], $e->context());
        }

        $this->assertSame(0, $this->calls);
        $this->assertDatabaseCount('payments', 0);
        Event::assertNothingDispatched();
    }

    public function test_the_order_status_is_re_read_rather_than_trusted_from_the_caller(): void
    {
        $order = $this->order();
        // Cancelled by another request after this one loaded the order.
        Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Cancelled->value]);

        $this->expectException(PaymentException::class);

        try {
            $this->service(fn (): ChargeResult => ChargeResult::successful('tx'))->process($order, $this->data());
        } finally {
            $this->assertSame(0, $this->calls);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function blockingPayments(): array
    {
        return [
            'successful payment' => ['successful', 'order_already_paid'],
            'pending payment' => ['pending', 'payment_in_progress'],
        ];
    }

    #[DataProvider('blockingPayments')]
    public function test_an_existing_blocking_payment_stops_a_new_charge(string $status, string $code): void
    {
        $order = $this->order();
        $existing = Payment::factory()->forOrder($order)->create(['status' => $status]);

        try {
            $this->service(fn (): ChargeResult => ChargeResult::successful('tx'))->process($order, $this->data());
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException $e) {
            $this->assertSame($code, $e->errorCode());
            $this->assertSame(409, $e->status());
            $this->assertSame(['payment_id' => $existing->id], $e->context());
        }

        $this->assertSame(0, $this->calls);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_replayed_key_returns_the_original_without_charging_again(): void
    {
        $order = $this->order();
        $service = $this->service(fn (): ChargeResult => ChargeResult::successful('tx_once'));

        $first = $service->process($order, $this->data('replay-key-1'));
        $second = $service->process($order, $this->data('replay-key-1'));

        $this->assertTrue($second->replayed);
        $this->assertTrue($second->payment->is($first->payment));
        $this->assertSame(1, $this->calls);
        $this->assertDatabaseCount('payments', 1);
    }
}
