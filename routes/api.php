<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaperController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TrendController;
use App\Http\Middleware\SessionAuth;
use App\Http\Middleware\AdminOnly;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| For the /autosurvey prefix, configure it in RouteServiceProvider or .htaccess.
|
*/

// Health check and version info
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'app' => [
            'name' => config('autosurvey.name'),
            'version' => config('autosurvey.version'),
            'developer' => config('autosurvey.developer'),
        ],
    ]);
});

// Auth routes (no authentication required for login/register)
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    // Requires authentication
    Route::middleware(SessionAuth::class)->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Protected routes (require authentication)
Route::middleware(SessionAuth::class)->group(function () {

    // Papers
    Route::get('/papers', [PaperController::class, 'index']);
    Route::get('/papers/stats', [PaperController::class, 'stats']);
    Route::get('/papers/{id}', [PaperController::class, 'show']);

    // Journals
    Route::get('/journals', [JournalController::class, 'index']);
    Route::get('/journals/{id}', [JournalController::class, 'show']);

    // Summaries
    Route::get('/summaries/providers', [SummaryController::class, 'providers']);
    Route::post('/summaries/generate', [SummaryController::class, 'generate']);
    Route::get('/summaries/{paperId}', [SummaryController::class, 'byPaper']);

    // User Settings (API keys)
    Route::get('/settings/api', [SettingsController::class, 'getApiSettings']);
    Route::put('/settings/api', [SettingsController::class, 'updateApiSettings']);
    Route::delete('/settings/api/{provider}', [SettingsController::class, 'deleteApiKey']);

    // Trends
    Route::prefix('trends')->group(function () {
        Route::get('/stats', [TrendController::class, 'stats']);
        Route::get('/{period}/papers', [TrendController::class, 'papers']);
        Route::get('/{period}/summary', [TrendController::class, 'summary']);
        Route::post('/{period}/generate', [TrendController::class, 'generate']);
    });

    // Admin routes (require authentication)
    Route::prefix('admin')->group(function () {
        // Journal management (authenticated users)
        Route::post('/journals', [AdminController::class, 'createJournal']);
        Route::post('/journals/test-rss', [AdminController::class, 'testRss']);
        Route::get('/journals/{id}/fetch', [AdminController::class, 'fetchJournal']);
        Route::put('/journals/{id}', [AdminController::class, 'updateJournal']);
        Route::delete('/journals/{id}', [AdminController::class, 'deleteJournal']);
        Route::post('/journals/{id}/activate', [AdminController::class, 'activateJournal']);

        // Admin-only routes
        Route::middleware(AdminOnly::class)->group(function () {
            Route::get('/scheduler/status', [AdminController::class, 'schedulerStatus']);
            Route::post('/scheduler/run', [AdminController::class, 'schedulerRun']);
            Route::get('/logs', [AdminController::class, 'logs']);
            Route::get('/users', [AdminController::class, 'users']);
        });
    });
});
