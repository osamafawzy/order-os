<?php

namespace Modules\Orders\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Event;
use Modules\Core\Services\BaseService;
use Modules\Orders\Contracts\OrderPaymentInspector;
use Modules\Orders\DTOs\CreateOrderData;
use Modules\Orders\DTOs\UpdateOrderData;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Events\OrderCreated;
use Modules\Orders\Events\OrderDeleted;
use Modules\Orders\Events\OrderStatusChanged;
use Modules\Orders\Events\OrderUpdated;
use Modules\Orders\Exceptions\OrderException;
use Modules\Orders\Models\Order;
use Modules\Orders\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Orders\Support\OrderTotalCalculator;

/**
 * The order lifecycle: draft, confirm, cancel, delete.
 *
 * Every rule about what may happen to an order is decided here and nowhere
 * else — the controller only translates HTTP, and the Payments module asks
 * this service for an order rather than reading the table itself.
 */
class OrderService extends BaseService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderTotalCalculator $calculator,
        private readonly OrderPaymentInspector $payments,
    ) {}

    public function paginate(int $userId, ?OrderStatus $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->orders->paginateForUser($userId, $status, $perPage);
    }

    /**
     * @param  bool  $withItems  false when the caller only needs ownership
     *                           and the order's own columns
     */
    public function findForUser(int $id, int $userId, bool $withItems = true): Order
    {
        return $this->orders->findForUserOrFail($id, $userId, $withItems);
    }

    /**
     * The order re-read under a row lock, for callers that must decide
     * something about it atomically — taking payment, chiefly. Only
     * meaningful inside a transaction the caller owns.
     */
    public function lockForUpdate(Order $order): Order
    {
        return $this->orders->lockForUpdate($order);
    }

    public function create(int $userId, CreateOrderData $data): Order
    {
        $totals = $this->calculator->calculate($data->items);

        $order = $this->transaction(fn (): Order => $this->orders->createWithItems([
            'user_id' => $userId,
            'customer_name' => $data->customer_name,
            'customer_email' => $data->customer_email,
            'customer_phone' => $data->customer_phone,
            'notes' => $data->notes,
            'status' => OrderStatus::Pending,
            'currency' => $this->currency(),
            'total' => $totals['total'],
        ], $totals['lines']));

        Event::dispatch(new OrderCreated($order));

        return $order;
    }

    /**
     * Apply a partial update.
     *
     * Detail changes and a status change may arrive together ("fix the email
     * and confirm"). Details are applied first, against the status the order
     * had when the request arrived — so a pending order can be corrected and
     * confirmed in one call, but a confirmed order cannot be edited by sending
     * a status alongside the edit.
     *
     * The rules are checked against the order re-read under a row lock — the
     * same lock taking payment holds — so a cancel cannot slip in between a
     * payment's checks and its charge, and two edits cannot race a confirm.
     */
    public function update(Order $order, UpdateOrderData $data): Order
    {
        $to = $data->status;
        $from = $order->status;
        $changesStatus = false;

        $order = $this->transaction(function () use ($order, $data, $to, &$from, &$changesStatus): Order {
            $order = $this->orders->lockForUpdate($order);
            $from = $order->status;

            if ($data->changesDetails() && ! $from->isEditable()) {
                throw OrderException::notEditable($from);
            }

            $changesStatus = $to !== null && $to !== $from;

            if ($changesStatus) {
                $this->assertTransition($order, $from, $to);
            }

            $attributes = $data->attributes();

            if ($data->items !== null) {
                $totals = $this->calculator->calculate($data->items);
                $this->orders->replaceItems($order, $totals['lines']);
                $attributes['total'] = $totals['total'];
            }

            if ($changesStatus) {
                $attributes['status'] = $to;
                $attributes[$to === OrderStatus::Confirmed ? 'confirmed_at' : 'cancelled_at'] = now();
            }

            /** @var Order $order */
            $order = $attributes === [] ? $order : $this->orders->update($order, $attributes);

            return $order->load('items');
        });

        Event::dispatch(new OrderUpdated($order));

        if ($changesStatus) {
            Event::dispatch(new OrderStatusChanged($order, $from, $to));
        }

        return $order;
    }

    /**
     * Deleting is refused once any payment exists — failed ones included. A
     * declined charge is still a record the gateway holds, and deleting the
     * order would leave that record pointing at nothing. The foreign key
     * enforces the same rule underneath, in case anything ever bypasses this.
     * Checked under the order's row lock, so a payment being opened at the
     * same moment queues behind the delete instead of meeting the foreign key
     * as a 500.
     */
    public function delete(Order $order): void
    {
        $id = $order->getKey();
        $userId = $order->user_id;

        $this->transaction(function () use ($order): void {
            $order = $this->orders->lockForUpdate($order);

            if ($this->payments->hasPayments($order)) {
                throw OrderException::hasPayments();
            }

            $this->orders->delete($order);
        });

        Event::dispatch(new OrderDeleted($id, $userId));
    }

    private function assertTransition(Order $order, OrderStatus $from, OrderStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw OrderException::invalidTransition($from, $to);
        }

        // Money has been taken. Cancelling now needs a refund flow, which is
        // out of scope, so it is refused rather than leaving a paid order
        // marked cancelled with nobody owed anything.
        if ($to === OrderStatus::Cancelled && $this->payments->hasSuccessfulPayment($order)) {
            throw OrderException::paidCannotBeCancelled();
        }

        // Likewise for money that may still arrive: a pending bank transfer
        // landing on a cancelled order is a refund nobody is expecting.
        if ($to === OrderStatus::Cancelled && $this->payments->hasPendingPayment($order)) {
            throw OrderException::paymentPending();
        }
    }

    private function currency(): string
    {
        return strtoupper((string) config('orders.currency', 'USD'));
    }
}
