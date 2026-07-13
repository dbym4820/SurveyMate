<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\Paper;
use App\Models\TrendSummary;
use App\Models\TrendSummaryMessage;
use App\Services\AiSummaryService;

class TrendChatController extends Controller
{
    public function __construct(
        private AiSummaryService $aiService
    ) {}

    /**
     * Get chat messages for a trend summary
     */
    public function index(Request $request, int $trendSummaryId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $trendSummary = $this->findOwnedSummary($trendSummaryId, $user->id);
        if (!$trendSummary) {
            return response()->json(['error' => 'トレンド要約が見つかりません'], 404);
        }

        $messages = TrendSummaryMessage::where('trend_summary_id', $trendSummaryId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($msg) => $this->formatMessage($msg));

        return response()->json([
            'success' => true,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message and get AI response
     */
    public function send(Request $request, int $trendSummaryId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        $trendSummary = $this->findOwnedSummary($trendSummaryId, $user->id);
        if (!$trendSummary) {
            return response()->json(['error' => 'トレンド要約が見つかりません'], 404);
        }

        $userMessage = $request->message;

        try {
            // Save user message
            $userMsg = TrendSummaryMessage::create([
                'trend_summary_id' => $trendSummaryId,
                'user_id' => $user->id,
                'role' => 'user',
                'content' => $userMessage,
            ]);

            // Get conversation history for context
            $history = TrendSummaryMessage::where('trend_summary_id', $trendSummaryId)
                ->where('id', '<', $userMsg->id)
                ->orderBy('created_at', 'asc')
                ->limit(20) // Limit history to prevent token overflow
                ->get()
                ->map(fn($msg) => [
                    'role' => $msg->role,
                    'content' => $msg->content,
                ])
                ->toArray();

            // Fetch papers matching the trend summary's period and filters
            $papers = $this->fetchPapersForSummary($trendSummary, $user->id);

            // Generate AI response
            $this->aiService->setUser($user);
            $result = $this->aiService->trendChat($trendSummary, $papers, $userMessage, $history);

            // Save AI response
            $aiMsg = TrendSummaryMessage::create([
                'trend_summary_id' => $trendSummaryId,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $result['content'],
                'ai_provider' => $result['provider'],
                'ai_model' => $result['model'],
                'tokens_used' => $result['tokens_used'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'user_message' => $this->formatMessage($userMsg),
                'ai_message' => $this->formatMessage($aiMsg),
            ]);

        } catch (\Exception $e) {
            // Delete the user message if AI response failed
            if (isset($userMsg)) {
                $userMsg->delete();
            }

            return response()->json([
                'error' => 'AIの応答生成に失敗しました: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear chat history for a trend summary
     */
    public function clear(Request $request, int $trendSummaryId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $trendSummary = $this->findOwnedSummary($trendSummaryId, $user->id);
        if (!$trendSummary) {
            return response()->json(['error' => 'トレンド要約が見つかりません'], 404);
        }

        TrendSummaryMessage::where('trend_summary_id', $trendSummaryId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'チャット履歴をクリアしました',
        ]);
    }

    private function findOwnedSummary(int $trendSummaryId, int $userId): ?TrendSummary
    {
        $trendSummary = TrendSummary::find($trendSummaryId);

        if (!$trendSummary || $trendSummary->user_id !== $userId) {
            return null;
        }

        return $trendSummary;
    }

    /**
     * Fetch papers matching the trend summary's date range and filters
     */
    private function fetchPapersForSummary(TrendSummary $trendSummary, int $userId)
    {
        $query = Paper::with('journal:id,name')
            ->forUser($userId)
            ->whereBetween('published_date', [
                $trendSummary->date_from?->startOfDay(),
                $trendSummary->date_to?->endOfDay(),
            ]);

        $tagIds = $trendSummary->tag_ids ?? [];
        if (!empty($tagIds)) {
            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            });
        }

        $journalIds = $trendSummary->journal_ids ?? [];
        if (!empty($journalIds)) {
            $query->whereIn('journal_id', $journalIds);
        }

        return $query->orderBy('published_date', 'desc')->get();
    }

    private function formatMessage(TrendSummaryMessage $msg): array
    {
        return [
            'id' => $msg->id,
            'role' => $msg->role,
            'content' => $msg->content,
            'ai_provider' => $msg->ai_provider,
            'ai_model' => $msg->ai_model,
            'tokens_used' => $msg->tokens_used,
            'created_at' => $msg->created_at->toISOString(),
        ];
    }
}
