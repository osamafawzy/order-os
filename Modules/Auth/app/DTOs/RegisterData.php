<?php

namespace Modules\Auth\DTOs;

use Modules\Core\Support\BaseDTO;

class RegisterData extends BaseDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static(
            name: trim((string) $data['name']),
            email: mb_strtolower(trim((string) $data['email'])),
            password: (string) $data['password'],
        );
    }
}
