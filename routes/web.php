<?php

use Illuminate\Support\Facades\Route;

// An API-only application: the root only says where the API is.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'health' => url('/up'),
    'documentation' => 'See README.md and docs/postman/ in the repository.',
]));
