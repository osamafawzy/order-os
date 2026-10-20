<?php

namespace Modules\Core\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * One JSON error shape for the whole API.
 *
 * Validation errors keep Laravel's own `{ message, errors }` body, which every
 * client library already understands. Everything else gets `{ message, code }`
 * so a client can branch on a stable string instead of parsing prose.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (DomainException $e, Request $request): ?JsonResponse {
            if (! self::isApi($request)) {
                return null;
            }

            return response()->json(array_filter([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
                'context' => $e->context() ?: null,
            ]), $e->status());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            return self::isApi($request)
                ? self::error(__('core::errors.unauthenticated'), 'unauthenticated', 401)
                : null;
        });

        // Route-model binding and findOrFail both land here. The model name is
        // deliberately not echoed back: "Order not found" for someone else's
        // order would confirm that the id exists.
        $exceptions->render(function (NotFoundHttpException $e, Request $request): ?JsonResponse {
            if (! self::isApi($request)) {
                return null;
            }

            return $e->getPrevious() instanceof ModelNotFoundException
                ? self::error(__('core::errors.resource_not_found'), 'resource_not_found', 404)
                : self::error(__('core::errors.route_not_found'), 'route_not_found', 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request): ?JsonResponse {
            return self::isApi($request)
                ? self::error(__('core::errors.method_not_allowed'), 'method_not_allowed', 405)
                : null;
        });

        // The Retry-After and X-RateLimit-* headers are kept: they are how a
        // well-behaved client knows when to come back.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request): ?JsonResponse {
            return self::isApi($request)
                ? self::error(__('core::errors.too_many_requests'), 'too_many_requests', 429)->withHeaders($e->getHeaders())
                : null;
        });

        // Anything unexpected, outside debug mode: the same shape as every
        // other error, and nothing about the internals. The exception is
        // still reported to the log by the framework before this runs.
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! self::isApi($request) || config('app.debug') || $e instanceof HttpExceptionInterface || $e instanceof ValidationException) {
                return null;
            }

            return self::error(__('core::errors.server_error'), 'server_error', 500);
        });
    }

    private static function isApi(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private static function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
