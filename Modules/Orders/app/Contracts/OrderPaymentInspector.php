<?php

namespace Modules\Orders\Contracts;

use Modules\Orders\Models\Order;

/**
 * What the Orders module needs to know about payments, and nothing more.
 *
 * Orders must refuse to delete an order that has payments, but it may not
 * reach into the Payments module to find out. This contract is the seam: the
 * Orders module binds a null implementation (no payments module, no
 * payments), and the Payments module rebinds it to one backed by its table.
 */
interface OrderPaymentInspector
{
    public function hasPayments(Order $order): bool;

    public function hasSuccessfulPayment(Order $order): bool;

    /** A payment accepted but not settled yet — a bank transfer in flight. */
    public function hasPendingPayment(Order $order): bool;
}
