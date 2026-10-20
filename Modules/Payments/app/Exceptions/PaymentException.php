<?php

namespace Modules\Payments\Exceptions;

use Modules\Core\Exceptions\DomainException;
use Modules\Orders\Enums\OrderStatus;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Symfony\Component\HttpFoundation\Response;

class PaymentException extends DomainException
{
    public static function orderNotConfirmed(OrderStatus $status): self
    {
        return new self(
            __('payments::errors.order_not_confirmed', ['status' => $status->value]),
            'order_not_confirmed',
            Response::HTTP_CONFLICT,
            ['order_status' => $status->value],
        );
    }

    public static function alreadySettled(Payment $payment): self
    {
        return $payment->status === PaymentStatus::Successful
            ? new self(__('payments::errors.order_already_paid'), 'order_already_paid', Response::HTTP_CONFLICT, ['payment_id' => $payment->id])
            : new self(__('payments::errors.payment_in_progress'), 'payment_in_progress', Response::HTTP_CONFLICT, ['payment_id' => $payment->id]);
    }

    /** @param array<int, string> $available */
    public static function unsupportedMethod(string $method, array $available): self
    {
        return new self(
            __('payments::errors.unsupported_method', ['method' => $method]),
            'unsupported_payment_method',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['available_methods' => $available],
        );
    }

    /** @param array<int, string> $available */
    public static function methodUnavailable(string $method, array $available): self
    {
        return new self(
            __('payments::errors.method_unavailable', ['method' => $method]),
            'payment_method_unavailable',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['available_methods' => $available],
        );
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(__('payments::errors.idempotency_key_reused'), 'idempotency_key_reused', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** The outcome is unknown; the payment stays pending until reconciled. */
    public static function gatewayError(Payment $payment): self
    {
        return new self(
            __('payments::errors.gateway_error'),
            'gateway_error',
            Response::HTTP_BAD_GATEWAY,
            ['payment_id' => $payment->id, 'payment_status' => $payment->status->value],
        );
    }

    /** Nothing was sent; the payment is failed and a retry is safe. */
    public static function gatewayUnreachable(Payment $payment): self
    {
        return new self(
            __('payments::errors.gateway_unreachable'),
            'gateway_unreachable',
            Response::HTTP_BAD_GATEWAY,
            ['payment_id' => $payment->id, 'payment_status' => $payment->status->value],
        );
    }
}
