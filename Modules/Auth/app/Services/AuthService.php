<?php

namespace Modules\Auth\Services;

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Auth\DTOs\AuthToken;
use Modules\Auth\DTOs\RegisterData;
use Modules\Auth\Exceptions\AuthException;
use Modules\Auth\Models\User;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;
use Modules\Core\Services\BaseService;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

/**
 * Registration and the JWT lifecycle: issue, refresh, revoke.
 *
 * Tokens are stateless, so "logout" means adding the token to the package's
 * blacklist — a revoked token is refused for the rest of its lifetime even
 * though nothing about the token itself changed.
 */
class AuthService extends BaseService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function register(RegisterData $data): AuthToken
    {
        try {
            $user = $this->transaction(function () use ($data): User {
                /** @var User $user */
                $user = $this->users->create([
                    'name' => $data->name,
                    'email' => $data->email,
                    // Hashed by the model's `hashed` cast.
                    'password' => $data->password,
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two registrations for one address both passed the `unique`
            // rule; the index let one through. The other gets the same 422
            // the rule would have given it.
            throw ValidationException::withMessages(['email' => __('auth::validation.email_taken')]);
        }

        Event::dispatch(new Registered($user));

        return $this->issueFor($user);
    }

    public function login(string $email, string $password): AuthToken
    {
        $token = $this->guard()->attempt([
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ]);

        if (! is_string($token)) {
            throw AuthException::invalidCredentials();
        }

        /** @var User $user */
        $user = $this->guard()->user();

        return $this->token($token, $user);
    }

    /**
     * Swap the current token for a fresh one. The old token is blacklisted
     * in the same step, so a refresh cannot be used to mint a second live
     * token from a stolen one.
     */
    public function refresh(): AuthToken
    {
        $token = $this->guard()->refresh();

        /** @var User $user */
        $user = $this->guard()->setToken($token)->user();

        return $this->token($token, $user);
    }

    public function logout(): void
    {
        $this->guard()->logout();
    }

    private function issueFor(User $user): AuthToken
    {
        return $this->token($this->guard()->login($user), $user);
    }

    private function token(string $token, User $user): AuthToken
    {
        return new AuthToken(
            accessToken: $token,
            expiresIn: $this->guard()->factory()->getTTL() * 60,
            user: $user,
        );
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard */
        return auth('api');
    }
}
