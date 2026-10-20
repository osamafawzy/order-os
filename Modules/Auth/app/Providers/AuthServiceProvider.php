<?php

namespace Modules\Auth\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;
use Modules\Auth\Repositories\UserRepository;
use Modules\Core\Providers\ModuleServiceProvider;

class AuthServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Auth';

    protected string $nameLower = 'auth';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Per email *and* per IP: one address being guessed from many
        // machines, and one machine guessing many addresses, both hit a wall.
        RateLimiter::for('auth', fn (Request $request): array => [
            Limit::perMinute((int) config('auth.login_attempts_per_minute', 10))
                ->by('email:'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinute((int) config('auth.login_attempts_per_minute', 10) * 3)
                ->by('ip:'.$request->ip()),
        ]);
    }
}
