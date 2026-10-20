<?php

namespace Modules\Auth\Repositories\Contracts;

use Modules\Auth\Models\User;
use Modules\Core\Repositories\Contracts\RepositoryInterface;

interface UserRepositoryInterface extends RepositoryInterface
{
    public function findByEmail(string $email): ?User;
}
