<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Journal;

class JournalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $journals = Journal::active()
            ->withCount('papers')
            ->orderBy('name')
            ->get()
            ->map(function ($j) {
                return [
                    'id' => $j->id,
                    'name' => $j->name,
                    'full_name' => $j->full_name,
                    'publisher' => $j->publisher,
                    'category' => $j->category,
                    'color' => $j->color,
                    'is_active' => $j->is_active,
                    'last_fetched_at' => $j->last_fetched_at ? $j->last_fetched_at->toISOString() : null,
                    'paper_count' => $j->papers_count,
                ];
            });

        return response()->json([
            'success' => true,
            'journals' => $journals,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $journal = Journal::with(['papers' => function ($query) {
            $query->orderBy('published_date', 'desc')
                ->limit(10);
        }])->find($id);

        if (!$journal) {
            return response()->json(['error' => '論文誌が見つかりません'], 404);
        }

        return response()->json([
            'id' => $journal->id,
            'name' => $journal->name,
            'full_name' => $journal->full_name,
            'publisher' => $journal->publisher,
            'rss_url' => $journal->rss_url,
            'category' => $journal->category,
            'color' => $journal->color,
            'is_active' => $journal->is_active,
            'last_fetched_at' => $journal->last_fetched_at ? $journal->last_fetched_at->toISOString() : null,
            'recent_papers' => $journal->papers->map(function ($p) {
                return [
                    'id' => $p->id,
                    'title' => $p->title,
                    'authors' => $p->authors,
                    'published_date' => $p->published_date ? $p->published_date->format('Y-m-d') : null,
                ];
            }),
        ]);
    }
}
