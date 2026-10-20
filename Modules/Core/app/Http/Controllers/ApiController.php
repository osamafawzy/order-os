<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Routing\Controller;

/**
 * Base for every API controller.
 *
 * Controllers stay thin: a form request validates and builds a DTO, a service
 * applies the rules, an API resource shapes the response. Nothing here may
 * touch Eloquent directly.
 */
abstract class ApiController extends Controller
{
    protected const DEFAULT_PER_PAGE = 15;

    protected function perPage(?int $requested): int
    {
        return $requested ?? self::DEFAULT_PER_PAGE;
    }
}
