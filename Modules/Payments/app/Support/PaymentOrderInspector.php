<?php

namespace Modules\Payments\Support;

use Modules\Orders\Contracts\OrderPaymentInspector;
use Modules\Orders\Models\Order;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Repositories\Contracts\PaymentRepositoryInterface;

/**
 * The real answer to the Orders module's question "does this order have
 * payments?", replacing its null default when this module is on.
 */
final class PaymentOrderInspector implements OrderPaymentInspector
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
    ) {}

    public function hasPayments(Order $order): bool
    {
        return $this->payments->existsForOrder($order->id);
    }

    public function hasSuccessfulPayment(Order $order): bool
    {
        return $this->payments->hasStatusForOrder($order->id, PaymentStatus::Successful);
    }

    public function hasPendingPayment(Order $order): bool
    {
        return $this->payments->hasStatusForOrder($order->id, PaymentStatus::Pending);
    }
}
