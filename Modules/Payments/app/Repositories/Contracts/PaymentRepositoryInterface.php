<?php

namespace Modules\Payments\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Payments\DTOs\PaymentFilters;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;

interface PaymentRepositoryInterface extends RepositoryInterface
{
    public function paginateForUser(int $userId, PaymentFilters $filters, int $perPage = 15): LengthAwarePaginator;

    /** @throws ModelNotFoundException */
    public function findForUserOrFail(int $id, int $userId): Payment;

    public function existsForOrder(int $orderId): bool;

    public function hasStatusForOrder(int $orderId, PaymentStatus $status): bool;

    /** The pending or successful payment that stops a new one, if any. */
    public function blockingForOrder(int $orderId): ?Payment;

    public function findByIdempotencyKey(int $userId, string $key): ?Payment;
}
