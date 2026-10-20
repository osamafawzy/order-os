<?php

namespace Modules\Orders\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries ids rather than the model: by the time a listener runs, the row
 * is gone and a serialized model could not be restored.
 */
class OrderDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
    ) {}
}
