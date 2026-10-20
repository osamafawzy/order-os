<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every API form request.
 *
 * Authorisation is not decided here. Every endpoint behind auth:api only ever
 * sees the caller's own records because the repositories scope by user, so a
 * per-request authorize() would be a second, weaker copy of that rule.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared rules for a paginated list endpoint.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): ?int
    {
        return $this->filled('per_page') ? $this->integer('per_page') : null;
    }
}
