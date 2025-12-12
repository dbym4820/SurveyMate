<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\Summary;
use App\Models\SummaryMessage;
use App\Services\AiSummaryService;

class SummaryChatController extends Controller
{
    public function __construct(
        private AiSummaryService $aiService
    ) {}

    /**
     * Get chat messages for a summary
     */
    public function index(Request $request, int $summaryId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $summary = Summary::with('paper.journal')->find($summaryId);

        if (!$summary) {
            return response()->json(['error' => '要約が見つかりません'], 404);
        }

        // Check if user owns the paper's journal
        if ($summary->paper->journal->user_id !== $user->id) {
            return response()->json(['error' => 'アクセス権限がありません'], 403);
        }

        $messages = SummaryMessage::where('summary_id', $summaryId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'ai_provider' => $msg->ai_provider,
                    'ai_model' => $msg->ai_model,
                    'tokens_used' => $msg->tokens_used,
                    'created_at' => $msg->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message and get AI response
     */
    public function send(Request $request, int $summaryId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        $summary = Summary::with('paper.journal')->find($summaryId);

        if (!$summary) {
            return response()->json(['error' => '要約が見つかりません'], 404);
        }

        // Check if user owns the paper's journal
        if ($summary->paper->journal->user_id !== $user->id) {
            return response()->json(['error' => 'アクセス権限がありません'], 403);
        }

        $userMessage = $request->message;

        try {
            // Save user message
            $userMsg = SummaryMessage::create([
                'summary_id' => $summaryId,
                'user_id' => $user->id,
                'role' => 'user',
                'content' => $userMessage,
            ]);

            // Get conversation history for context
            $history = SummaryMessage::where('summary_id', $summaryId)
                ->where('id', '<', $userMsg->id)
                ->orderBy('created_at', 'asc')
                ->limit(20) // Limit history to prevent token overflow
                ->get()
                ->map(function ($msg) {
                    return [
                        'role' => $msg->role,
                        'content' => $msg->content,
                    ];
                })
                ->toArray();

            // Generate AI response
            $this->aiService->setUser($user);
            $result = $this->aiService->chat($summary, $userMessage, $history);

            // Save AI response
            $aiMsg = SummaryMessage::create([
                'summary_id' => $summaryId,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $result['content'],
                'ai_provider' => $result['provider'],
                'ai_model' => $result['model'],
                'tokens_used' => $result['tokens_used'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'user_message' => [
                    'id' => $userMsg->id,
                    'role' => 'user',
                    'content' => $userMessage,
                    'created_at' => $userMsg->created_at->toISOString(),
                ],
                'ai_message' => [
                    'id' => $aiMsg->id,
                    'role' => 'assistant',
                    'content' => $result['content'],
                    'ai_provider' => $result['provider'],
                    'ai_model' => $result['model'],
                    'tokens_used' => $result['tokens_used'] ?? null,
                    'created_at' => $aiMsg->created_at->toISOString(),
                ],
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
     * Clear chat history for a summary
     */
    public function clear(Request $request, int $summaryId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $summary = Summary::with('paper.journal')->find($summaryId);

        if (!$summary) {
            return response()->json(['error' => '要約が見つかりません'], 404);
        }

        // Check if user owns the paper's journal
        if ($summary->paper->journal->user_id !== $user->id) {
            return response()->json(['error' => 'アクセス権限がありません'], 403);
        }

        SummaryMessage::where('summary_id', $summaryId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'チャット履歴をクリアしました',
        ]);
    }
}
