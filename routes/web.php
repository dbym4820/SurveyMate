<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SsoController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| All requests that don't match /api/* will be handled by the SPA.
| The React app is served via Blade template with Vite.
|
*/

// HCIMLab SSO (OIDC) - ブラウザリダイレクトを伴うためWebルートに配置
// （SPAのcatch-allより先に定義すること）
Route::get('/auth/sso/redirect', [SsoController::class, 'redirect']);
Route::get('/auth/callback', [SsoController::class, 'callback']);

// SPA - all routes go to the React app
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
