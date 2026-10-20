<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;

/**
 * Business rules and orchestration live in services. Anything that spans more
 * than one model, needs a transaction, or fires domain events belongs here —
 * never in a controller or a form request.
 */
abstract class BaseService
{
    /**
     * Run a unit of work in a transaction.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
