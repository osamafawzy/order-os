<?php

namespace Modules\Orders\Support;

use Modules\Orders\Contracts\OrderPaymentInspector;
use Modules\Orders\Models\Order;

/**
 * The answer when the Payments module is switched off: there are none.
 */
final class NullOrderPaymentInspector implements OrderPaymentInspector
{
    public function hasPayments(Order $order): bool
    {
        return false;
    }

    public function hasSuccessfulPayment(Order $order): bool
    {
        return false;
    }

    public function hasPendingPayment(Order $order): bool
    {
        return false;
    }
}
