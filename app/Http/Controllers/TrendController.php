<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Paper;
use App\Models\TrendSummary;
use App\Services\AiSummaryService;
use Carbon\Carbon;

class TrendController extends Controller
{
    /** @var AiSummaryService */
    private $aiService;

    public function __construct(AiSummaryService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Get papers for a specific period
     */
    public function papers(Request $request, string $period): JsonResponse
    {
        $user = $request->attributes->get('user');

        // カスタム日付範囲の場合はクエリパラメータから取得
        $dateFrom = $request->query('dateFrom');
        $dateTo = $request->query('dateTo');
        $dateRange = $this->getDateRange($period, $dateFrom, $dateTo);

        if (!$dateRange) {
            return response()->json(['error' => '無効な期間です'], 400);
        }

        // Get tag IDs from query string (optional)
        $tagIds = $request->query('tagIds', []);
        if (is_string($tagIds)) {
            $tagIds = $tagIds ? array_map('intval', explode(',', $tagIds)) : [];
        }

        // Get journal IDs from query string (optional)
        $journalIds = $request->query('journalIds', []);
        if (is_string($journalIds)) {
            $journalIds = $journalIds ? explode(',', $journalIds) : [];
        }

        $papersQuery = Paper::with('journal:id,name,color')
            ->forUser($user->id)
            ->whereBetween('published_date', [$dateRange['from'], $dateRange['to']]);

        // Filter by tags if specified
        if (!empty($tagIds)) {
            $papersQuery->whereHas('tags', function ($query) use ($tagIds) {
                $query->whereIn('tags.id', $tagIds);
            });
        }

        // Filter by journals if specified
        if (!empty($journalIds)) {
            $papersQuery->whereIn('journal_id', $journalIds);
        }

        $papers = $papersQuery->orderBy('published_date', 'desc')
            ->get()
            ->map(function ($paper) {
                return [
                    'id' => $paper->id,
                    'title' => $paper->title,
                    'authors' => $paper->authors,
                    'abstract' => $paper->abstract,
                    'published_date' => $paper->published_date ? $paper->published_date->format('Y-m-d') : null,
                    'journal_name' => $paper->journal ? $paper->journal->name : null,
                    'journal_color' => $paper->journal ? $paper->journal->color : 'bg-gray-500',
                ];
            });

        return response()->json([
            'success' => true,
            'period' => $period,
            'dateRange' => [
                'from' => $dateRange['from']->format('Y-m-d'),
                'to' => $dateRange['to']->format('Y-m-d'),
            ],
            'papers' => $papers,
            'count' => $papers->count(),
            'tagIds' => $tagIds,
            'journalIds' => $journalIds,
        ]);
    }

    /**
     * Generate AI trend summary for a period
     */
    public function generate(Request $request, string $period): JsonResponse
    {
        $user = $request->attributes->get('user');

        // カスタム日付範囲の場合はリクエストボディから取得
        $dateFrom = $request->input('dateFrom');
        $dateTo = $request->input('dateTo');
        $dateRange = $this->getDateRange($period, $dateFrom, $dateTo);

        if (!$dateRange) {
            return response()->json(['error' => '無効な期間です'], 400);
        }

        // Get tag IDs from request (optional)
        $tagIds = $request->input('tagIds', []);
        if (!is_array($tagIds)) {
            $tagIds = [];
        }

        // Get journal IDs from request (optional)
        $journalIds = $request->input('journalIds', []);
        if (!is_array($journalIds)) {
            $journalIds = [];
        }

        $papersQuery = Paper::with(['journal:id,name', 'tags:id,name'])
            ->forUser($user->id)
            ->whereBetween('published_date', [$dateRange['from'], $dateRange['to']]);

        // Filter by tags if specified
        if (!empty($tagIds)) {
            $papersQuery->whereHas('tags', function ($query) use ($tagIds) {
                $query->whereIn('tags.id', $tagIds);
            });
        }

        // Filter by journals if specified
        if (!empty($journalIds)) {
            $papersQuery->whereIn('journal_id', $journalIds);
        }

        $papers = $papersQuery->orderBy('published_date', 'desc')->get();

        if ($papers->isEmpty()) {
            return response()->json([
                'success' => true,
                'period' => $period,
                'summary' => null,
                'message' => 'この期間に論文がありません',
            ]);
        }

        // Set user context for API key resolution
        if ($user) {
            $this->aiService->setUser($user);
        }

        // Get selected provider from request
        $provider = $request->input('provider', config('services.ai.provider', 'claude'));

        try {
            $summary = $this->generateTrendSummary($user->id, $papers, $period, $dateRange, $provider, $tagIds, $journalIds);

            return response()->json([
                'success' => true,
                'period' => $period,
                'dateRange' => [
                    'from' => $dateRange['from']->format('Y-m-d'),
                    'to' => $dateRange['to']->format('Y-m-d'),
                ],
                'paperCount' => $papers->count(),
                'provider' => $provider,
                'tagIds' => $tagIds,
                'journalIds' => $journalIds,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'トレンド要約の生成に失敗しました: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get saved trend summary from database
     */
    public function summary(Request $request, string $period): JsonResponse
    {
        $user = $request->attributes->get('user');

        // カスタム日付範囲の場合はクエリパラメータから取得
        $customDateFrom = $request->query('dateFrom');
        $customDateTo = $request->query('dateTo');
        $dateRange = $this->getDateRange($period, $customDateFrom, $customDateTo);

        if (!$dateRange) {
            return response()->json(['error' => '無効な期間です'], 400);
        }

        $dateFrom = $dateRange['from']->format('Y-m-d');
        $dateTo = $dateRange['to']->format('Y-m-d');

        // Get tag IDs from query string (optional)
        $tagIds = $request->query('tagIds', []);
        if (is_string($tagIds)) {
            $tagIds = $tagIds ? array_map('intval', explode(',', $tagIds)) : [];
        }

        // Get journal IDs from query string (optional)
        $journalIds = $request->query('journalIds', []);
        if (is_string($journalIds)) {
            $journalIds = $journalIds ? explode(',', $journalIds) : [];
        }

        // Try to get latest summary for user with matching tags and journals
        $savedSummary = TrendSummary::findLatestForUser($user->id, $period, $tagIds ?: null, $journalIds ?: null);

        if ($savedSummary) {
            return response()->json([
                'success' => true,
                'period' => $period,
                'dateRange' => [
                    'from' => $dateFrom,
                    'to' => $dateTo,
                ],
                'saved' => true,
                'provider' => $savedSummary->ai_provider,
                'model' => $savedSummary->ai_model,
                'paperCount' => $savedSummary->paper_count,
                'tagIds' => $savedSummary->tag_ids ?? [],
                'journalIds' => $savedSummary->journal_ids ?? [],
                'summary' => $savedSummary->toApiResponse(),
            ]);
        }

        return response()->json([
            'success' => true,
            'period' => $period,
            'dateRange' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
            'saved' => false,
            'summary' => null,
        ]);
    }

    /**
     * Get trend summary history for a user
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $limit = (int) $request->query('limit', 20);

        $summaries = TrendSummary::getHistoryForUser($user->id, $limit);

        return response()->json([
            'success' => true,
            'summaries' => $summaries->map(fn($s) => $s->toApiResponse()),
        ]);
    }

    /**
     * Get statistics for all periods
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $periods = ['day', 'week', 'month', 'halfyear'];
        $stats = [];

        foreach ($periods as $period) {
            $dateRange = $this->getDateRange($period);
            $count = Paper::forUser($user->id)
                ->whereBetween('published_date', [$dateRange['from'], $dateRange['to']])
                ->count();

            $stats[$period] = [
                'count' => $count,
                'dateRange' => [
                    'from' => $dateRange['from']->format('Y-m-d'),
                    'to' => $dateRange['to']->format('Y-m-d'),
                ],
            ];
        }

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Get date range for a period
     */
    private function getDateRange(string $period, ?string $dateFrom = null, ?string $dateTo = null): ?array
    {
        // カスタム期間の場合
        if ($period === 'custom') {
            if (!$dateFrom || !$dateTo) {
                return null;
            }
            try {
                $from = Carbon::parse($dateFrom)->startOfDay();
                $to = Carbon::parse($dateTo)->endOfDay();
                return ['from' => $from, 'to' => $to];
            } catch (\Exception $e) {
                return null;
            }
        }

        $now = Carbon::now();
        $to = $now->copy()->endOfDay();

        switch ($period) {
            case 'day':
                $from = $now->copy()->startOfDay();
                break;
            case 'week':
                $from = $now->copy()->subWeek()->startOfDay();
                break;
            case 'month':
                $from = $now->copy()->subMonth()->startOfDay();
                break;
            case 'halfyear':
                $from = $now->copy()->subMonths(6)->startOfDay();
                break;
            default:
                return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Generate trend summary using AI
     */
    private function generateTrendSummary(int $userId, $papers, string $period, array $dateRange, string $provider = 'claude', array $tagIds = [], array $journalIds = []): array
    {
        $periodLabels = [
            'day' => '今日',
            'week' => '今週',
            'month' => '今月',
            'halfyear' => '過去半年',
        ];

        // カスタム期間の場合は日付範囲を表示
        if ($period === 'custom') {
            $periodLabel = $dateRange['from']->format('Y/m/d') . ' 〜 ' . $dateRange['to']->format('Y/m/d');
        } else {
            $periodLabel = $periodLabels[$period] ?? $period;
        }

        // Group papers by journal
        $byJournal = $papers->groupBy(function ($paper) {
            return $paper->journal ? $paper->journal->name : 'その他';
        });

        // Prepare paper summaries for AI
        $paperSummaries = $papers->take(50)->map(function ($paper) {
            return [
                'title' => $paper->title,
                'journal' => $paper->journal ? $paper->journal->name : null,
                'abstract' => $paper->abstract ? mb_substr($paper->abstract, 0, 500) : null,
            ];
        })->toArray();

        $prompt = $this->buildTrendPrompt($periodLabel, $paperSummaries, $byJournal);

        $result = $this->aiService->generateCustomSummary($prompt, $provider);

        // Save to database (always create new record for history)
        $dateFrom = $dateRange['from']->format('Y-m-d');
        $dateTo = $dateRange['to']->format('Y-m-d');

        TrendSummary::createSummary([
            'user_id' => $userId,
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'ai_provider' => $provider,
            'ai_model' => config("services.ai.{$provider}_model"),
            'overview' => $result['overview'] ?? null,
            'key_topics' => $result['keyTopics'] ?? null,
            'emerging_trends' => $result['emergingTrends'] ?? null,
            'journal_insights' => $result['journalInsights'] ?? null,
            'recommendations' => $result['recommendations'] ?? null,
            'paper_count' => $papers->count(),
            'tag_ids' => !empty($tagIds) ? $tagIds : null,
            'journal_ids' => !empty($journalIds) ? $journalIds : null,
        ]);

        return $result;
    }

    /**
     * Build prompt for trend analysis
     */
    private function buildTrendPrompt(string $periodLabel, array $papers, $byJournal): string
    {
        $journalStats = $byJournal->map(function ($items, $journal) {
            return "{$journal}: {$items->count()}件";
        })->implode(', ');

        $paperList = collect($papers)->map(function ($paper, $index) {
            $num = $index + 1;
            $abstract = $paper['abstract'] ? "\n   概要: {$paper['abstract']}" : '';
            return "{$num}. [{$paper['journal']}] {$paper['title']}{$abstract}";
        })->implode("\n\n");

        return <<<PROMPT
あなたは教育工学・AI教育・認知科学分野の専門家です．
{$periodLabel}に収集された学術論文のトレンドを分析し，日本語で要約してください．

## 論文統計
- 総数: {$byJournal->flatten()->count()}件
- 論文誌別: {$journalStats}

## 論文リスト
{$paperList}

## 要約の形式
以下の形式でJSON形式で出力してください：

{
  "overview": "全体的なトレンドの概要（200-300文字）",
  "keyTopics": [
    {
      "topic": "トピック名",
      "description": "説明（100-150文字）",
      "paperCount": 関連論文数
    }
  ],
  "emergingTrends": [
    "新しく注目されているトレンド1",
    "新しく注目されているトレンド2"
  ],
  "journalInsights": {
    "論文誌名": "その論文誌の傾向（50-100文字）"
  },
  "recommendations": [
    "研究者へのおすすめ論文や方向性1",
    "研究者へのおすすめ論文や方向性2"
  ]
}

重要:
- JSON形式のみを出力し，それ以外のテキストは含めないでください．
- 日本語の句読点は必ず「，」（カンマ）と「．」（ピリオド）を使用してください．「、」と「。」は絶対に使用しないでください．
PROMPT;
    }

}
