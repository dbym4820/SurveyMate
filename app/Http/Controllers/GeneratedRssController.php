<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Journal;
use App\Services\AiRssGeneratorService;
use App\Services\RssFetcherService;

class GeneratedRssController extends Controller
{
    private AiRssGeneratorService $aiGenerator;
    private RssFetcherService $rssFetcher;

    public function __construct(AiRssGeneratorService $aiGenerator, RssFetcherService $rssFetcher)
    {
        $this->aiGenerator = $aiGenerator;
        $this->rssFetcher = $rssFetcher;
    }

    /**
     * Reanalyze page structure and fetch papers (requires authentication)
     */
    public function regenerate(Request $request, string $journalId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $journal = Journal::forUser($user->id)->find($journalId);

        if (!$journal) {
            return response()->json([
                'success' => false,
                'error' => 'Journal not found',
            ], 404);
        }

        if (!$journal->isAiGenerated()) {
            return response()->json([
                'success' => false,
                'error' => 'This journal is not AI-generated',
            ], 400);
        }

        // AIでページ構造を再解析してセレクタを更新
        $result = $this->aiGenerator->reanalyzeStructure($journal, $user);

        if (!$result['success']) {
            $response = [
                'success' => false,
                'error' => $result['error'],
            ];
            if (!empty($result['debug'])) {
                $response['debug'] = $result['debug'];
            }
            return response()->json($response, 400);
        }

        // HTMLからパースした論文情報をデータベースに登録
        $fetchResult = $this->rssFetcher->fetchJournal($journal);
        $newPapers = $fetchResult['new_papers'] ?? 0;

        return response()->json([
            'success' => true,
            'message' => 'ページを再解析しました（' . $newPapers . '件の新規論文を登録）',
            'papers_count' => $result['papers_count'],
            'new_papers' => $newPapers,
            'provider' => $result['provider'] ?? null,
        ]);
    }

    /**
     * Test page analysis (requires authentication)
     */
    public function testPage(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $url = $request->input('url');
        $autoRedirect = $request->input('auto_redirect', true);

        if (!$url) {
            return response()->json([
                'success' => false,
                'error' => 'URL is required',
            ], 400);
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid URL format',
            ], 400);
        }

        // Check if user has API key
        if (!$user->hasClaudeApiKey() && !$user->hasOpenaiApiKey()) {
            return response()->json([
                'success' => false,
                'error' => 'No AI API key configured. Please set your API key in Settings.',
            ], 400);
        }

        // Use auto-redirect version if enabled
        if ($autoRedirect) {
            $result = $this->aiGenerator->testPageAnalysisWithRedirect($url, $user);
        } else {
            $result = $this->aiGenerator->testPageAnalysis($url, $user);
        }

        $response = [
            'success' => $result['success'],
            'is_article_list_page' => $result['is_article_list_page'] ?? null,
            'page_type' => $result['page_type'] ?? null,
            'page_type_reason' => $result['page_type_reason'] ?? null,
            'provider' => $result['provider'] ?? null,
            'page_size' => $result['page_size'] ?? null,
        ];

        // Add redirect info if available
        if (!empty($result['redirect_history'])) {
            $response['redirect_history'] = $result['redirect_history'];
        }
        if (!empty($result['final_url'])) {
            $response['final_url'] = $result['final_url'];
        }
        if (!empty($result['article_list_url'])) {
            $response['article_list_url'] = $result['article_list_url'];
        }

        if (!$result['success']) {
            $response['error'] = $result['error'] ?? 'Analysis failed';
            return response()->json($response, 400);
        }

        $response['selectors'] = $result['selectors'] ?? [];
        $response['sample_papers'] = $result['sample_papers'] ?? [];

        return response()->json($response);
    }
}
