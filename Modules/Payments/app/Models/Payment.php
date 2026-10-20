<?php

namespace Modules\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;
use Modules\Orders\Models\Order;
use Modules\Payments\Database\Factories\PaymentFactory;
use Modules\Payments\DTOs\PaymentFilters;
use Modules\Payments\Enums\PaymentStatus;

/**
 * One attempt to pay for an order. A declined card and the retry that
 * succeeds are two rows, so the history of an order's payments is complete.
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property string $method
 * @property PaymentStatus $status
 * @property string $amount
 * @property string $currency
 * @property string|null $transaction_id
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $meta
 * @property string|null $idempotency_key
 */
class Payment extends BaseModel
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'decimal:2',
        'meta' => 'array',
        'processed_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeFilter(Builder $query, PaymentFilters $filters): Builder
    {
        return $query
            ->when($filters->status, fn (Builder $q, PaymentStatus $s) => $q->where('status', $s->value))
            ->when($filters->method, fn (Builder $q, string $m) => $q->where('method', $m))
            ->when($filters->order_id, fn (Builder $q, int $id) => $q->where('order_id', $id));
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (PaymentStatus $s): string => $s->value,
            array_filter(PaymentStatus::cases(), fn (PaymentStatus $s): bool => $s->blocksNewPayment()),
        ));
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
