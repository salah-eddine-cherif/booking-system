<?php

use Illuminate\Support\Facades\Route;

/*
| This is an API-only application; the booking UI is expected to be a separate
| front end. The root route just points at the versioned API.
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1/services'),
    'health' => url('/up'),
]));
