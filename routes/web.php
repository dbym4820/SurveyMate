<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| All requests that don't match /api/* will be handled by the SPA.
| The React app is served via Blade template with Vite.
|
*/

// SPA - all routes go to the React app
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
