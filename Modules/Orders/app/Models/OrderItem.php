<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\BaseModel;

/**
 * One line of an order. The price is copied in rather than looked up: an
 * order records what the customer agreed to pay, whatever the product costs
 * tomorrow.
 *
 * @property int $id
 * @property string $product_name
 * @property int $quantity
 * @property string $price
 * @property string $line_total
 */
class OrderItem extends BaseModel
{
    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
