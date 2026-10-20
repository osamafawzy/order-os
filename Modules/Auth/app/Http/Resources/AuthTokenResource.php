<?php

namespace Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Auth\DTOs\AuthToken;

/** @property AuthToken $resource */
class AuthTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'access_token' => $this->resource->accessToken,
            'token_type' => $this->resource->tokenType,
            'expires_in' => $this->resource->expiresIn,
            'user' => new UserResource($this->resource->user),
        ];
    }
}
