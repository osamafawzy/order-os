<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Auth\Models\User;
use Modules\Core\Models\BaseModel;
use Modules\Orders\Database\Factories\OrderFactory;
use Modules\Orders\Enums\OrderStatus;

/**
 * @property int $id
 * @property int $user_id
 * @property string $customer_name
 * @property string $customer_email
 * @property string|null $customer_phone
 * @property OrderStatus $status
 * @property string $currency
 * @property string $total
 * @property string|null $notes
 */
class Order extends BaseModel
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $casts = [
        'status' => OrderStatus::class,
        'total' => 'decimal:2',
        'confirmed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWithStatus(Builder $query, ?OrderStatus $status): Builder
    {
        return $status === null ? $query : $query->where('status', $status->value);
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
