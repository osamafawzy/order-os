<?php

namespace Modules\Payments\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Modules\Core\Services\BaseService;
use Modules\Orders\Models\Order;
use Modules\Orders\Services\OrderService;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\DTOs\PaymentFilters;
use Modules\Payments\DTOs\ProcessedPayment;
use Modules\Payments\DTOs\ProcessPaymentData;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentFailed;
use Modules\Payments\Events\PaymentProcessed;
use Modules\Payments\Events\PaymentSucceeded;
use Modules\Payments\Exceptions\GatewayUnreachable;
use Modules\Payments\Exceptions\PaymentException;
use Modules\Payments\Models\Payment;
use Modules\Payments\Repositories\Contracts\PaymentRepositoryInterface;
use Throwable;

/**
 * Taking payment for an order.
 *
 * This class never names a gateway. It enforces the rules every payment
 * shares — the order must be confirmed, must not already be paid or have a
 * payment in flight, and is charged its own total — then hands the charge to
 * whichever PaymentGateway the registry returns for the requested method.
 *
 * The sequence is deliberate:
 *
 *   1. Under a row lock on the order, check the rules and record a pending
 *      payment. Two simultaneous requests for one order queue on that lock,
 *      and the second sees the first's pending row and is refused.
 *   2. Outside any transaction, call the gateway. A slow vendor must not hold
 *      a database lock open for seconds.
 *   3. Record what the gateway said.
 */
class PaymentService extends BaseService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly GatewayRegistry $gateways,
        private readonly OrderService $orders,
    ) {}

    public function paginate(int $userId, PaymentFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->payments->paginateForUser($userId, $filters, $perPage);
    }

    public function findForUser(int $id, int $userId): Payment
    {
        return $this->payments->findForUserOrFail($id, $userId);
    }

    public function process(Order $order, ProcessPaymentData $data): ProcessedPayment
    {
        if ($replayed = $this->replay($order, $data)) {
            return $replayed;
        }

        $gateway = $this->gateways->available($data->method);

        try {
            $opened = $this->open($order, $gateway, $data);
        } catch (UniqueConstraintViolationException $e) {
            // Two requests with one Idempotency-Key both reached the insert;
            // the unique index let exactly one through. Answer the loser
            // with the winner's payment, as a replay would have.
            return $this->replay($order, $data) ?? throw $e;
        }

        return $opened instanceof ProcessedPayment
            ? $opened
            : new ProcessedPayment($this->charge($opened, $order, $gateway, $data));
    }

    /**
     * Same key, same user: return the original payment and charge nothing.
     * The key is bound to the order it was first used for, so a client bug
     * that reuses one key across orders is caught rather than silently
     * answered with another order's payment.
     */
    private function replay(Order $order, ProcessPaymentData $data): ?ProcessedPayment
    {
        if ($data->idempotency_key === null) {
            return null;
        }

        $existing = $this->payments->findByIdempotencyKey($order->user_id, $data->idempotency_key);

        if ($existing === null) {
            return null;
        }

        if ($existing->order_id !== $order->id) {
            throw PaymentException::idempotencyKeyReused();
        }

        return new ProcessedPayment($existing, replayed: true);
    }

    /**
     * A new pending payment — or, when a request with the same key won the
     * race for the lock, that request's payment as a replay.
     */
    private function open(Order $order, PaymentGateway $gateway, ProcessPaymentData $data): Payment|ProcessedPayment
    {
        return $this->transaction(function () use ($order, $gateway, $data): Payment|ProcessedPayment {
            $locked = $this->orders->lockForUpdate($order);

            // Asked again under the lock. Two same-key requests can both miss
            // the first lookup; the second to get here must see the first's
            // row as its own replay, not as a payment blocking it.
            if ($replayed = $this->replay($locked, $data)) {
                return $replayed;
            }

            if (! $locked->status->acceptsPayment()) {
                throw PaymentException::orderNotConfirmed($locked->status);
            }

            if ($blocking = $this->payments->blockingForOrder($locked->id)) {
                throw PaymentException::alreadySettled($blocking);
            }

            /** @var Payment */
            return $this->payments->create([
                'order_id' => $locked->id,
                'user_id' => $locked->user_id,
                'method' => $gateway->key(),
                'status' => PaymentStatus::Pending,
                'amount' => $locked->total,
                'currency' => $locked->currency,
                'idempotency_key' => $data->idempotency_key,
            ]);
        });
    }

    private function charge(Payment $payment, Order $order, PaymentGateway $gateway, ProcessPaymentData $data): Payment
    {
        $request = new ChargeRequest(
            paymentId: $payment->id,
            orderId: $order->id,
            amount: (string) $payment->amount,
            currency: $payment->currency,
            customerName: $order->customer_name,
            customerEmail: $order->customer_email,
            details: $data->details,
        );

        try {
            $result = $gateway->charge($request);
        } catch (GatewayUnreachable $e) {
            // The request provably never reached the vendor, so no money can
            // have moved. Failed, and the Idempotency-Key released: a client
            // retrying with the same key gets a fresh attempt, not this
            // error played back.
            report($e);

            $payment = $this->record(
                $payment,
                ChargeResult::failed('gateway_unreachable', __('payments::errors.gateway_unreachable')),
                ['idempotency_key' => null],
            );

            throw PaymentException::gatewayUnreachable($payment);
        } catch (Throwable $e) {
            // Anything else — a timeout above all — is an UNKNOWN outcome:
            // the vendor may have captured the money and failed to say so.
            // Marking it failed would let a retry charge the customer twice,
            // so it stays pending, blocking new attempts until reconciliation
            // learns what really happened. The key keeps replaying it.
            report($e);

            $payment = $this->record($payment, ChargeResult::unknown('gateway_error', __('payments::errors.gateway_error')));

            throw PaymentException::gatewayError($payment);
        }

        return $this->record($payment, $result);
    }

    /** @param array<string, mixed> $extra columns written in the same update */
    private function record(Payment $payment, ChargeResult $result, array $extra = []): Payment
    {
        /** @var Payment $payment */
        $payment = $this->payments->update($payment, $extra + [
            'status' => $result->status,
            'transaction_id' => $result->transactionId,
            'failure_code' => $result->failureCode,
            'failure_message' => $result->failureMessage,
            'meta' => $result->meta ?: null,
            'processed_at' => $result->status === PaymentStatus::Pending ? null : now(),
        ]);

        Event::dispatch(new PaymentProcessed($payment));

        match ($payment->status) {
            PaymentStatus::Successful => Event::dispatch(new PaymentSucceeded($payment)),
            PaymentStatus::Failed => Event::dispatch(new PaymentFailed($payment)),
            PaymentStatus::Pending => null,
        };

        return $payment;
    }
}
