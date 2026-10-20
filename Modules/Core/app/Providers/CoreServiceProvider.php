<?php

namespace Modules\Core\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class CoreServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Core';

    protected string $nameLower = 'core';

    public function boot(): void
    {
        parent::boot();

        // Keyed by user once authenticated, by IP before: a whole office
        // behind one NAT should not share a single budget.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(
            (int) config('core.rate_limit_per_minute', 60),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
