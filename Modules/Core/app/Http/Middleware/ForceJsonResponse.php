<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API response is JSON, whatever the client put in Accept.
 *
 * Without this a request from curl with no Accept header that fails validation
 * gets a 302 redirect to "/" — the web behaviour — instead of a 422 body.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
