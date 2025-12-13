<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaperController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TrendController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\SummaryChatController;
use App\Http\Controllers\GeneratedRssController;
use App\Http\Middleware\SessionAuth;
use App\Http\Middleware\AdminOnly;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| For the /surveymate prefix, configure it in RouteServiceProvider or .htaccess.
|
*/

// Health check and version info
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'app' => [
            'name' => config('surveymate.name'),
            'version' => config('surveymate.version'),
            'developer' => config('surveymate.developer'),
        ],
    ]);
});

// Auth routes (no authentication required for login/register/me)
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/me', [AuthController::class, 'me']);

    // Requires authentication
    Route::middleware(SessionAuth::class)->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Push notifications (public endpoint for getting VAPID public key)
Route::get('/push/public-key', [PushController::class, 'publicKey']);

// Protected routes (require authentication)
Route::middleware(SessionAuth::class)->group(function () {

    // Push notifications
    Route::prefix('push')->group(function () {
        Route::post('/subscribe', [PushController::class, 'subscribe']);
        Route::post('/unsubscribe', [PushController::class, 'unsubscribe']);
        Route::get('/status', [PushController::class, 'status']);
    });

    // Papers
    Route::get('/papers', [PaperController::class, 'index']);
    Route::get('/papers/stats', [PaperController::class, 'stats']);
    Route::get('/papers/{id}', [PaperController::class, 'show']);
    Route::get('/papers/{id}/full-text', [PaperController::class, 'getFullText']);
    Route::get('/papers/{id}/pdf', [PaperController::class, 'downloadPdf']);

    // Tags
    Route::prefix('tags')->group(function () {
        Route::get('/', [TagController::class, 'index']);
        Route::post('/', [TagController::class, 'store']);
        Route::put('/{id}', [TagController::class, 'update']);
        Route::delete('/{id}', [TagController::class, 'destroy']);
        Route::get('/{id}/papers', [TagController::class, 'papersByTag']);
        // タグ要約
        Route::get('/{id}/summaries', [TagController::class, 'getSummaries']);
        Route::post('/{id}/summaries', [TagController::class, 'generateSummary']);
        Route::delete('/{id}/summaries/{summaryId}', [TagController::class, 'deleteSummary']);
    });

    // Paper tags
    Route::post('/papers/{paperId}/tags', [TagController::class, 'addTagToPaper']);
    Route::delete('/papers/{paperId}/tags/{tagId}', [TagController::class, 'removeTagFromPaper']);

    // Journals
    Route::get('/journals', [JournalController::class, 'index']);
    Route::get('/journals/{id}', [JournalController::class, 'show']);

    // Summaries
    Route::get('/summaries/providers', [SummaryController::class, 'providers']);
    Route::post('/summaries/generate', [SummaryController::class, 'generate']);
    Route::get('/summaries/{paperId}', [SummaryController::class, 'byPaper']);

    // Summary Chat (AI conversation about summaries)
    Route::prefix('summaries/{summaryId}/chat')->group(function () {
        Route::get('/', [SummaryChatController::class, 'index']);
        Route::post('/', [SummaryChatController::class, 'send']);
        Route::delete('/', [SummaryChatController::class, 'clear']);
    });

    // User Settings (API keys)
    Route::get('/settings/api', [SettingsController::class, 'getApiSettings']);
    Route::put('/settings/api', [SettingsController::class, 'updateApiSettings']);
    Route::delete('/settings/api/{provider}', [SettingsController::class, 'deleteApiKey']);

    // User Profile
    Route::get('/settings/profile', [SettingsController::class, 'getProfile']);
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile']);

    // Research Perspective（調査観点設定）
    Route::get('/settings/research-perspective', [SettingsController::class, 'getResearchPerspective']);
    Route::put('/settings/research-perspective', [SettingsController::class, 'updateResearchPerspective']);

    // Summary Template（要約テンプレート設定）
    Route::get('/settings/summary-template', [SettingsController::class, 'getSummaryTemplate']);
    Route::put('/settings/summary-template', [SettingsController::class, 'updateSummaryTemplate']);

    // Initial Setup（初期設定）
    Route::post('/settings/initial-setup/complete', [SettingsController::class, 'completeInitialSetup']);
    Route::post('/settings/initial-setup/skip', [SettingsController::class, 'skipInitialSetup']);

    // Trends
    Route::prefix('trends')->group(function () {
        Route::get('/stats', [TrendController::class, 'stats']);
        Route::get('/history', [TrendController::class, 'history']);
        Route::get('/{period}/papers', [TrendController::class, 'papers']);
        Route::get('/{period}/summary', [TrendController::class, 'summary']);
        Route::post('/{period}/generate', [TrendController::class, 'generate']);
    });

    // Admin routes (require authentication)
    Route::prefix('admin')->group(function () {
        // Journal management (authenticated users)
        Route::post('/journals', [AdminController::class, 'createJournal']);
        Route::post('/journals/test-rss', [AdminController::class, 'testRss']);
        Route::post('/journals/test-page', [GeneratedRssController::class, 'testPage']);
        Route::post('/journals/fetch-all', [AdminController::class, 'fetchAllJournals']);
        Route::get('/journals/{id}/fetch', [AdminController::class, 'fetchJournal']);
        Route::post('/journals/{id}/regenerate-feed', [GeneratedRssController::class, 'regenerate']);
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
