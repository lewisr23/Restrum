<?php

use Illuminate\Support\Facades\Route;

/*
 * The browser-facing app is the React SPA in frontend/, served by nginx at the
 * site root (see docker/nginx.conf), so Laravel renders no views at all. Only
 * /api, /broadcasting/auth and the /up health check reach PHP.
 *
 * This route exists so that hitting the backend directly, without nginx in
 * front of it, says where the API is rather than returning a bare 404.
 */
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/ping'),
]));
