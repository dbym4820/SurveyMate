<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GeneratedRssController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| All requests that don't match /api/* will be handled by the SPA.
| The React app is served via Blade template with Vite.
|
*/

// Public RSS feed endpoint (no authentication required)
Route::get('/rss/{feedToken}', [GeneratedRssController::class, 'serve'])
    ->where('feedToken', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

// SPA - all routes go to the React app
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|rss).*$');
