<?php

namespace Modules\Core\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    public function query(): Builder;

    public function find(int|string $id): ?Model;

    public function findOrFail(int|string $id): Model;

    /** @return Collection<int, Model> */
    public function all(): Collection;

    public function paginate(int $perPage = 15): LengthAwarePaginator;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Model;

    /** @param array<string, mixed> $attributes */
    public function update(Model $model, array $attributes): Model;

    public function delete(Model $model): bool;
}
