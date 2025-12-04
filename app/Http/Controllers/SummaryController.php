<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\Paper;
use App\Models\Summary;
use App\Services\AiSummaryService;

class SummaryController extends Controller
{
    /** @var AiSummaryService */
    private $aiService;

    public function __construct(AiSummaryService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function providers(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        if ($user) {
            $this->aiService->setUser($user);
        }

        $providers = $this->aiService->getAvailableProviders();
        $current = $user && $user->preferred_ai_provider
            ? $user->preferred_ai_provider
            : config('services.ai.provider', 'claude');

        return response()->json([
            'success' => true,
            'providers' => $providers,
            'current' => $current,
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'paperId' => 'required|integer|exists:papers,id',
            'provider' => 'nullable|string|in:claude,openai',
            'model' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        $paper = Paper::with('journal')->find($request->paperId);

        if (!$paper) {
            return response()->json(['error' => '論文が見つかりません'], 404);
        }

        try {
            // Set user context for API key resolution
            $user = $request->attributes->get('user');
            if ($user) {
                $this->aiService->setUser($user);
            }

            // Determine provider: request > user preference > system config
            $provider = $request->provider;
            if (!$provider && $user && $user->preferred_ai_provider) {
                $provider = $user->preferred_ai_provider;
            }
            if (!$provider) {
                $provider = config('services.ai.provider', 'claude');
            }
            $model = $request->model;

            $result = $this->aiService->generateSummary($paper, $provider, $model);

            $summary = Summary::create([
                'paper_id' => $paper->id,
                'ai_provider' => $result['provider'],
                'ai_model' => $result['model'],
                'summary_text' => $result['summary_text'],
                'purpose' => $result['purpose'] ?? null,
                'methodology' => $result['methodology'] ?? null,
                'findings' => $result['findings'] ?? null,
                'implications' => $result['implications'] ?? null,
                'tokens_used' => $result['tokens_used'] ?? null,
                'generation_time_ms' => $result['generation_time_ms'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => '要約を生成しました',
                'summary' => [
                    'id' => $summary->id,
                    'ai_provider' => $summary->ai_provider,
                    'ai_model' => $summary->ai_model,
                    'summary_text' => $summary->summary_text,
                    'purpose' => $summary->purpose,
                    'methodology' => $summary->methodology,
                    'findings' => $summary->findings,
                    'implications' => $summary->implications,
                    'tokens_used' => $summary->tokens_used,
                    'generation_time_ms' => $summary->generation_time_ms,
                    'created_at' => $summary->created_at ? $summary->created_at->toISOString() : null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => '要約の生成に失敗しました: ' . $e->getMessage()
            ], 500);
        }
    }

    public function byPaper(Request $request, int $paperId): JsonResponse
    {
        $summaries = Summary::where('paper_id', $paperId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'ai_provider' => $s->ai_provider,
                    'ai_model' => $s->ai_model,
                    'summary_text' => $s->summary_text,
                    'purpose' => $s->purpose,
                    'methodology' => $s->methodology,
                    'findings' => $s->findings,
                    'implications' => $s->implications,
                    'tokens_used' => $s->tokens_used,
                    'created_at' => $s->created_at ? $s->created_at->toISOString() : null,
                ];
            });

        return response()->json($summaries);
    }
}
