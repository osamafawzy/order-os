<?php

namespace Modules\Orders\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;

interface OrderRepositoryInterface extends RepositoryInterface
{
    public function paginateForUser(int $userId, ?OrderStatus $status = null, int $perPage = 15): LengthAwarePaginator;

    /**
     * One of the user's own orders. Someone else's order is indistinguishable
     * from one that does not exist.
     *
     * @throws ModelNotFoundException
     */
    public function findForUserOrFail(int $id, int $userId, bool $withItems = true): Order;

    /**
     * Re-read the order holding a row lock until the surrounding transaction
     * ends. Used where two requests racing on one order must queue.
     */
    public function lockForUpdate(Order $order): Order;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createWithItems(array $attributes, array $lines): Order;

    /** @param array<int, array<string, mixed>> $lines */
    public function replaceItems(Order $order, array $lines): void;
}
