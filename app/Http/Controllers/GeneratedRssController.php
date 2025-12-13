<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Models\GeneratedFeed;
use App\Models\Journal;
use App\Services\AiRssGeneratorService;

class GeneratedRssController extends Controller
{
    private AiRssGeneratorService $rssGenerator;

    public function __construct(AiRssGeneratorService $rssGenerator)
    {
        $this->rssGenerator = $rssGenerator;
    }

    /**
     * Serve RSS feed by feed token (public, no authentication required)
     * Dynamically fetches and parses the source page using saved selectors
     */
    public function serve(string $feedToken): Response
    {
        $feed = GeneratedFeed::where('feed_token', $feedToken)
            ->with('journal')
            ->first();

        if (!$feed) {
            return response('Feed not found', 404)
                ->header('Content-Type', 'text/plain');
        }

        // Check if we have valid selectors
        $selectors = $feed->extraction_config['selectors'] ?? null;
        if (!$selectors || empty($selectors['title'])) {
            return response('Feed not configured. Please regenerate the feed.', 503)
                ->header('Content-Type', 'text/plain');
        }

        // Dynamically generate RSS by fetching and parsing the source page
        try {
            $rssXml = $this->rssGenerator->generateRssDynamically($feed);

            return response($rssXml)
                ->header('Content-Type', 'application/rss+xml; charset=utf-8')
                ->header('Cache-Control', 'public, max-age=1800'); // Cache for 30 minutes
        } catch (\Exception $e) {
            \Log::error('Failed to generate RSS dynamically', [
                'feed_token' => $feedToken,
                'error' => $e->getMessage(),
            ]);

            return response('Failed to fetch feed: ' . $e->getMessage(), 503)
                ->header('Content-Type', 'text/plain');
        }
    }

    /**
     * Regenerate feed (requires authentication)
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

        $result = $this->rssGenerator->generateFeed($journal, $user);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Feed regenerated successfully',
            'papers_count' => $result['papers_count'],
            'feed_token' => $result['feed_token'],
            'provider' => $result['provider'] ?? ($result['method'] === 'selector' ? 'selector' : null),
        ]);
    }

    /**
     * Test page analysis (requires authentication)
     */
    public function testPage(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $url = $request->input('url');
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

        $result = $this->rssGenerator->testPageAnalysis($url, $user);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'selectors' => $result['selectors'] ?? [],
            'sample_papers' => $result['sample_papers'] ?? [],
            'provider' => $result['provider'],
            'page_size' => $result['page_size'] ?? null,
        ]);
    }
}
