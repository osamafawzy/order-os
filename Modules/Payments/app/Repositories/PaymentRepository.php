<?php

namespace Modules\Payments\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Core\Repositories\BaseRepository;
use Modules\Payments\DTOs\PaymentFilters;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Repositories\Contracts\PaymentRepositoryInterface;

class PaymentRepository extends BaseRepository implements PaymentRepositoryInterface
{
    protected string $model = Payment::class;

    public function paginateForUser(int $userId, PaymentFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()
            ->ownedBy($userId)
            ->filter($filters)
            ->latest()
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForUserOrFail(int $id, int $userId): Payment
    {
        /** @var Payment */
        return $this->query()->ownedBy($userId)->findOrFail($id);
    }

    public function existsForOrder(int $orderId): bool
    {
        return $this->query()->where('order_id', $orderId)->exists();
    }

    public function hasStatusForOrder(int $orderId, PaymentStatus $status): bool
    {
        return $this->query()
            ->where('order_id', $orderId)
            ->where('status', $status->value)
            ->exists();
    }

    public function blockingForOrder(int $orderId): ?Payment
    {
        /** @var Payment|null */
        return $this->query()->where('order_id', $orderId)->blocking()->latest('id')->first();
    }

    public function findByIdempotencyKey(int $userId, string $key): ?Payment
    {
        /** @var Payment|null */
        return $this->query()->ownedBy($userId)->where('idempotency_key', $key)->first();
    }
}
