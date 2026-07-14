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
// 注意: この2ルートは認証ミドルウェアの内側に置かないこと（ゲスト到達可能を維持）．
// 未ログイン→intended に callback URL 保存→ログイン後に code 付き URL へ復帰，
// という循環が起きると，消費済みコードの再交換（invalid_grant）で必ず失敗する．
Route::get('/auth/sso/redirect', [SsoController::class, 'redirect']);
Route::get('/auth/callback', [SsoController::class, 'callback']);

// SPA - all routes go to the React app
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
