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
        $dateRange = $this->getDateRange($period);

        if (!$dateRange) {
            return response()->json(['error' => '無効な期間です'], 400);
        }

        $papers = Paper::with('journal:id,name,full_name,color,category')
            ->whereBetween('published_date', [$dateRange['from'], $dateRange['to']])
            ->orderBy('published_date', 'desc')
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
                    'category' => $paper->journal ? $paper->journal->category : null,
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
        ]);
    }

    /**
     * Generate AI trend summary for a period
     */
    public function generate(Request $request, string $period): JsonResponse
    {
        $dateRange = $this->getDateRange($period);

        if (!$dateRange) {
            return response()->json(['error' => '無効な期間です'], 400);
        }

        $papers = Paper::with('journal:id,name,full_name,category')
            ->whereBetween('published_date', [$dateRange['from'], $dateRange['to']])
            ->orderBy('published_date', 'desc')
            ->get();

        if ($papers->isEmpty()) {
            return response()->json([
                'success' => true,
                'period' => $period,
                'summary' => null,
                'message' => 'この期間に論文がありません',
            ]);
        }

        // Set user context for API key resolution
        $user = $request->attributes->get('user');
        if ($user) {
            $this->aiService->setUser($user);
        }

        // Get selected provider from request
        $provider = $request->input('provider', config('services.ai.provider', 'claude'));

        try {
            $summary = $this->generateTrendSummary($papers, $period, $dateRange, $provider);

            return response()->json([
                'success' => true,
                'period' => $period,
                'dateRange' => [
                    'from' => $dateRange['from']->format('Y-m-d'),
                    'to' => $dateRange['to']->format('Y-m-d'),
                ],
                'paperCount' => $papers->count(),
                'provider' => $provider,
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
        $dateRange = $this->getDateRange($period);

        if (!$dateRange) {
            return response()->json(['error' => '無効な期間です'], 400);
        }

        $dateFrom = $dateRange['from']->format('Y-m-d');
        $dateTo = $dateRange['to']->format('Y-m-d');

        // Try to get from database
        $savedSummary = TrendSummary::findByPeriodAndDate($period, $dateFrom, $dateTo);

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
     * Get statistics for all periods
     */
    public function stats(Request $request): JsonResponse
    {
        $periods = ['day', 'week', 'month', 'halfyear'];
        $stats = [];

        foreach ($periods as $period) {
            $dateRange = $this->getDateRange($period);
            $count = Paper::whereBetween('published_date', [$dateRange['from'], $dateRange['to']])->count();

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
    private function getDateRange(string $period): ?array
    {
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
    private function generateTrendSummary($papers, string $period, array $dateRange, string $provider = 'claude'): array
    {
        $periodLabels = [
            'day' => '今日',
            'week' => '今週',
            'month' => '今月',
            'halfyear' => '過去半年',
        ];

        $periodLabel = $periodLabels[$period] ?? $period;

        // Group papers by category
        $byCategory = $papers->groupBy(function ($paper) {
            return $paper->journal ? $paper->journal->category : 'その他';
        });

        // Prepare paper summaries for AI
        $paperSummaries = $papers->take(50)->map(function ($paper) {
            return [
                'title' => $paper->title,
                'category' => $paper->journal ? $paper->journal->category : null,
                'journal' => $paper->journal ? $paper->journal->name : null,
                'abstract' => $paper->abstract ? mb_substr($paper->abstract, 0, 500) : null,
            ];
        })->toArray();

        $prompt = $this->buildTrendPrompt($periodLabel, $paperSummaries, $byCategory);

        $result = $this->aiService->generateCustomSummary($prompt, $provider);

        // Save to database
        $dateFrom = $dateRange['from']->format('Y-m-d');
        $dateTo = $dateRange['to']->format('Y-m-d');

        TrendSummary::createOrUpdateSummary([
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'ai_provider' => $provider,
            'ai_model' => config("services.ai.{$provider}_model"),
            'overview' => $result['overview'] ?? null,
            'key_topics' => $result['keyTopics'] ?? null,
            'emerging_trends' => $result['emergingTrends'] ?? null,
            'category_insights' => $result['categoryInsights'] ?? null,
            'recommendations' => $result['recommendations'] ?? null,
            'paper_count' => $papers->count(),
        ]);

        return $result;
    }

    /**
     * Build prompt for trend analysis
     */
    private function buildTrendPrompt(string $periodLabel, array $papers, $byCategory): string
    {
        $categoryStats = $byCategory->map(function ($items, $category) {
            return "{$category}: {$items->count()}件";
        })->implode(', ');

        $paperList = collect($papers)->map(function ($paper, $index) {
            $num = $index + 1;
            $abstract = $paper['abstract'] ? "\n   概要: {$paper['abstract']}" : '';
            return "{$num}. [{$paper['journal']}] {$paper['title']}{$abstract}";
        })->implode("\n\n");

        return <<<PROMPT
あなたは教育工学・AI教育・認知科学分野の専門家です。
{$periodLabel}に収集された学術論文のトレンドを分析し、日本語で要約してください。

## 論文統計
- 総数: {$byCategory->flatten()->count()}件
- カテゴリ別: {$categoryStats}

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
  "categoryInsights": {
    "カテゴリ名": "そのカテゴリの傾向（50-100文字）"
  },
  "recommendations": [
    "研究者へのおすすめ論文や方向性1",
    "研究者へのおすすめ論文や方向性2"
  ]
}

JSON形式のみを出力し、それ以外のテキストは含めないでください。
PROMPT;
    }

}
