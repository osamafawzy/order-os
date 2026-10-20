<?php

namespace Modules\Orders\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Core\Repositories\BaseRepository;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Modules\Orders\Repositories\Contracts\OrderRepositoryInterface;

class OrderRepository extends BaseRepository implements OrderRepositoryInterface
{
    protected string $model = Order::class;

    public function paginateForUser(int $userId, ?OrderStatus $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()
            ->ownedBy($userId)
            ->withStatus($status)
            ->with('items')
            ->latest()
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForUserOrFail(int $id, int $userId, bool $withItems = true): Order
    {
        /** @var Order */
        return $this->query()
            ->ownedBy($userId)
            ->when($withItems, fn ($query) => $query->with('items'))
            ->findOrFail($id);
    }

    public function lockForUpdate(Order $order): Order
    {
        /** @var Order */
        return $this->query()->lockForUpdate()->findOrFail($order->getKey());
    }

    public function createWithItems(array $attributes, array $lines): Order
    {
        /** @var Order $order */
        $order = $this->create($attributes);

        $order->items()->createMany($lines);

        return $order->load('items');
    }

    public function replaceItems(Order $order, array $lines): void
    {
        $order->items()->delete();
        $order->items()->createMany($lines);
    }
}
