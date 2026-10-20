<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Requests\RegisterRequest;
use Modules\Auth\Http\Resources\AuthTokenResource;
use Modules\Auth\Http\Resources\UserResource;
use Modules\Auth\Services\AuthService;
use Modules\Core\Http\Controllers\ApiController;

class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthService $auth,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return (new AuthTokenResource($this->auth->register($request->toData())))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): AuthTokenResource
    {
        return new AuthTokenResource($this->auth->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
        ));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function refresh(): AuthTokenResource
    {
        return new AuthTokenResource($this->auth->refresh());
    }

    public function logout(): Response
    {
        $this->auth->logout();

        return response()->noContent();
    }
}
