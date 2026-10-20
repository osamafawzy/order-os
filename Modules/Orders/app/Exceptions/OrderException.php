<?php

namespace Modules\Orders\Exceptions;

use Modules\Core\Exceptions\DomainException;
use Modules\Orders\Enums\OrderStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every reason the Orders module refuses a request, each with a stable code.
 *
 * 409 Conflict where the request is well-formed but clashes with the order's
 * current state; 422 where the request itself asks for something impossible.
 */
class OrderException extends DomainException
{
    public static function hasPayments(): self
    {
        return new self(__('orders::errors.has_payments'), 'order_has_payments', Response::HTTP_CONFLICT);
    }

    public static function notEditable(OrderStatus $status): self
    {
        return new self(
            __('orders::errors.not_editable', ['status' => $status->value]),
            'order_not_editable',
            Response::HTTP_CONFLICT,
            ['status' => $status->value],
        );
    }

    public static function invalidTransition(OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            __('orders::errors.invalid_transition', ['from' => $from->value, 'to' => $to->value]),
            'invalid_status_transition',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            [
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => array_map(fn (OrderStatus $s): string => $s->value, $from->allowedTransitions()),
            ],
        );
    }

    public static function paymentPending(): self
    {
        return new self(__('orders::errors.payment_pending'), 'payment_in_progress', Response::HTTP_CONFLICT);
    }

    public static function paidCannotBeCancelled(): self
    {
        return new self(__('orders::errors.paid_cannot_be_cancelled'), 'order_already_paid', Response::HTTP_CONFLICT);
    }
}
