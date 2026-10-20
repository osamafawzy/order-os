<?php

namespace Modules\Auth\Http\Requests;

use Modules\Core\Http\Requests\ApiFormRequest;

class LoginRequest extends ApiFormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
