<?php

namespace Modules\Core\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Mounts a module's routes/api.php under the versioned API prefix.
 *
 * The sibling products repeat this body in every module; here it lives once,
 * and a module's RouteServiceProvider only names itself. Bumping the API to
 * v2 is then a change to one constant rather than to every module.
 */
abstract class ModuleRouteServiceProvider extends ServiceProvider
{
    public const API_PREFIX = 'api/v1';

    protected string $name;

    public function map(): void
    {
        $path = module_path($this->name, '/routes/api.php');

        if (! is_file($path)) {
            return;
        }

        Route::middleware('api')
            ->prefix(self::API_PREFIX)
            ->name('api.v1.')
            ->group($path);
    }
}
