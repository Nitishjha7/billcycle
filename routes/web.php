<?php

use Illuminate\Support\Facades\Route;

/**
 * Everything is the React SPA (resources/js). All real routing happens
 * client-side via React Router; the backend only serves JSON from
 * routes/api.php. This fallback keeps a hard refresh on any client-side
 * route (e.g. /customers/{id}) working instead of 404ing.
 */
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
