<?php

namespace Modules\Core\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Typed data crossing a layer boundary. Form requests build DTOs, services
 * consume them — so a change to a request cannot silently break a service.
 *
 * @implements Arrayable<string, mixed>
 */
abstract class BaseDTO implements Arrayable
{
    /** @param array<string, mixed> $data */
    abstract public static function fromArray(array $data): static;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(
            get_object_vars($this),
            static fn ($value) => $value !== null,
        );
    }
}
