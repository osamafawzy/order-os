<?php

namespace Modules\Auth\Repositories;

use Modules\Auth\Models\User;
use Modules\Auth\Repositories\Contracts\UserRepositoryInterface;
use Modules\Core\Repositories\BaseRepository;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected string $model = User::class;

    public function findByEmail(string $email): ?User
    {
        /** @var User|null */
        return $this->query()->where('email', mb_strtolower($email))->first();
    }
}
