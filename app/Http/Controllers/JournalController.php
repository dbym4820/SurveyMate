<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Journal;

class JournalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $showAll = $request->boolean('all', false);

        $query = Journal::forUser($user->id)
            ->withCount('papers')
            ->with('generatedFeed');

        if (!$showAll) {
            $query->active();
        }

        $journals = $query->orderBy('name')
            ->get()
            ->map(function ($j) {
                $data = [
                    'id' => $j->id,
                    'name' => $j->name,
                    'rss_url' => $j->rss_url,
                    'color' => $j->color,
                    'is_active' => $j->is_active,
                    'source_type' => $j->source_type ?? 'rss',
                    'last_fetched_at' => $j->last_fetched_at ? $j->last_fetched_at->toISOString() : null,
                    'paper_count' => $j->papers_count,
                ];

                // AI生成フィードの情報を追加
                if ($j->generatedFeed) {
                    $data['generated_feed'] = [
                        'id' => $j->generatedFeed->id,
                        'source_url' => $j->generatedFeed->source_url,
                        'ai_provider' => $j->generatedFeed->ai_provider,
                        'ai_model' => $j->generatedFeed->ai_model,
                        'generation_status' => $j->generatedFeed->generation_status,
                        'error_message' => $j->generatedFeed->error_message,
                        'last_generated_at' => $j->generatedFeed->last_generated_at?->toISOString(),
                    ];
                }

                return $data;
            });

        return response()->json([
            'success' => true,
            'journals' => $journals,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $journal = Journal::forUser($user->id)
            ->with(['papers' => function ($query) {
                $query->orderBy('published_date', 'desc')
                    ->limit(10);
            }, 'generatedFeed'])->find($id);

        if (!$journal) {
            return response()->json(['error' => '論文誌が見つかりません'], 404);
        }

        $response = [
            'id' => $journal->id,
            'name' => $journal->name,
            'rss_url' => $journal->rss_url,
            'color' => $journal->color,
            'is_active' => $journal->is_active,
            'source_type' => $journal->source_type ?? 'rss',
            'last_fetched_at' => $journal->last_fetched_at ? $journal->last_fetched_at->toISOString() : null,
            'recent_papers' => $journal->papers->map(function ($p) {
                return [
                    'id' => $p->id,
                    'title' => $p->title,
                    'authors' => $p->authors,
                    'published_date' => $p->published_date ? $p->published_date->format('Y-m-d') : null,
                ];
            }),
        ];

        // AI生成フィードの情報を追加
        if ($journal->generatedFeed) {
            $response['generated_feed'] = [
                'id' => $journal->generatedFeed->id,
                'source_url' => $journal->generatedFeed->source_url,
                'ai_provider' => $journal->generatedFeed->ai_provider,
                'ai_model' => $journal->generatedFeed->ai_model,
                'generation_status' => $journal->generatedFeed->generation_status,
                'error_message' => $journal->generatedFeed->error_message,
                'last_generated_at' => $journal->generatedFeed->last_generated_at?->toISOString(),
            ];
        }

        return response()->json($response);
    }
}
