<?php

namespace Modules\Core\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A business rule refused the request.
 *
 * Every module's exceptions extend this, so the renderer can turn any of them
 * into the same JSON shape without knowing a single module by name:
 *
 *     { "message": "...", "code": "order_not_confirmed" }
 *
 * The code is the contract a client branches on; the message is for people
 * and may be reworded at any time.
 */
abstract class DomainException extends RuntimeException
{
    final public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
        /** @var array<string, mixed> */
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
