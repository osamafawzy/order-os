<?php

namespace Modules\Auth\DTOs;

use Modules\Auth\Models\User;

/**
 * An issued access token and who it was issued to.
 */
final readonly class AuthToken
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public User $user,
        public string $tokenType = 'bearer',
    ) {}
}
