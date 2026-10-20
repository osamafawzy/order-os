<?php

namespace Tests\Concerns;

use Modules\Auth\Models\User;

/**
 * Authenticate test requests with real JWTs.
 *
 * actingAs() would put a user straight onto the guard and skip everything
 * that can actually go wrong: parsing the header, verifying the signature,
 * checking the blacklist. These helpers issue a genuine token and send it as
 * a bearer header instead, so every request goes through the real guard.
 *
 * In production every request is a fresh process. In a test they all share
 * one application, and both the JWT guard and the JWT parser cache the user
 * and token of the first request they saw. Without a reset, a token that was
 * revoked by /logout would still "work" on the next request of the same
 * test. call() therefore forgets the guards, every scoped instance and the
 * router's cached controllers before each request, which is what the next
 * PHP-FPM worker would start with.
 */
trait InteractsWithJwt
{
    protected ?string $jwt = null;

    /**
     * Issue a real token for the user (a new one if none is given) and send
     * it on every following request of this test.
     */
    protected function actingAsJwt(?User $user = null): User
    {
        $user ??= User::factory()->create();

        $this->jwt = $this->issueJwt($user);
        $this->withToken($this->jwt);

        return $user;
    }

    /** A valid token for the user, without attaching it to any request. */
    protected function issueJwt(User $user): string
    {
        $token = auth('api')->login($user);

        // login() also logs the user in on the in-memory guard. Forget that,
        // or the next request would be authenticated without its header.
        $this->forgetJwtState();

        return $token;
    }

    protected function forgetJwtState(): void
    {
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();

        // The router keeps each controller instance — and the services
        // injected into it — on its Route object after the first dispatch.
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }
    }

    /** @param  array<string, mixed>  $parameters */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->forgetJwtState();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
