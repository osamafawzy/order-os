<?php

namespace Modules\Auth\Exceptions;

use Modules\Core\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

class AuthException extends DomainException
{
    /**
     * One message for "no such email" and "wrong password" alike, so the
     * login endpoint cannot be used to discover who has an account.
     */
    public static function invalidCredentials(): self
    {
        return new self(__('auth::errors.invalid_credentials'), 'invalid_credentials', Response::HTTP_UNAUTHORIZED);
    }
}
