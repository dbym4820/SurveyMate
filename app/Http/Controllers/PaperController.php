<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Paper;
use Illuminate\Support\Facades\DB;

class PaperController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $query = Paper::withJournalInfo()->withSummaries()->withTagsInfo()->forUser($user->id);

        // Filter by journals
        if ($request->has('journals')) {
            $journalIds = array_filter(explode(',', $request->journals));
            $query->inJournals($journalIds);
        }

        // Filter by tags
        if ($request->has('tags')) {
            $tagIds = array_filter(array_map('intval', explode(',', $request->tags)));
            $query->withTags($tagIds);
        }

        // Filter by date range
        $query->publishedBetween($request->dateFrom, $request->dateTo);

        // Search
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        // Pagination
        $limit = min((int) ($request->limit ?? 20), 100);
        $offset = (int) ($request->offset ?? 0);

        $total = $query->count();
        $papers = $query->orderBy('published_date', 'desc')
            ->orderBy('fetched_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $self = $this;
        return response()->json([
            'success' => true,
            'papers' => $papers->map(function ($paper) use ($self) {
                return $self->formatPaper($paper);
            }),
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'hasMore' => ($offset + $limit) < $total,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $paper = Paper::withJournalInfo()->withSummaries()->withTagsInfo()->forUser($user->id)->find($id);

        if (!$paper) {
            return response()->json(['error' => '論文が見つかりません'], 404);
        }

        return response()->json($this->formatPaper($paper, true));
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $query = Paper::forUser($user->id);

        if ($request->dateFrom) {
            $query->where('published_date', '>=', $request->dateFrom);
        }
        if ($request->dateTo) {
            $query->where('published_date', '<=', $request->dateTo);
        }

        $stats = $query->selectRaw('journal_id, COUNT(*) as paper_count')
            ->groupBy('journal_id')
            ->get()
            ->keyBy('journal_id');

        return response()->json($stats);
    }

    public function getFullText(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $paper = Paper::forUser($user->id)->find($id);

        if (!$paper) {
            return response()->json(['success' => false, 'error' => '論文が見つかりません'], 404);
        }

        if (!$paper->hasFullText()) {
            return response()->json(['success' => false, 'error' => '本文が取得されていません'], 404);
        }

        return response()->json([
            'success' => true,
            'paper_id' => $paper->id,
            'title' => $paper->title,
            'full_text' => $paper->full_text,
            'full_text_source' => $paper->full_text_source,
            'pdf_url' => $paper->pdf_url,
            'full_text_fetched_at' => $paper->full_text_fetched_at?->toISOString(),
        ]);
    }

    private function formatPaper(Paper $paper, bool $detailed = false): array
    {
        $data = [
            'id' => $paper->id,
            'external_id' => $paper->external_id,
            'journal_id' => $paper->journal_id,
            'title' => $paper->title,
            'authors' => $paper->authors,
            'abstract' => $paper->abstract,
            'url' => $paper->url,
            'doi' => $paper->doi,
            'published_date' => $paper->published_date ? $paper->published_date->format('Y-m-d') : null,
            'fetched_at' => $paper->fetched_at ? $paper->fetched_at->toISOString() : null,
            // Flat fields for frontend compatibility
            'journal_name' => $paper->journal ? $paper->journal->name : null,
            'journal_color' => $paper->journal ? $paper->journal->color : 'bg-gray-500',
            // Nested journal object (for backward compatibility)
            'journal' => $paper->journal ? [
                'id' => $paper->journal->id,
                'name' => $paper->journal->name,
                'color' => $paper->journal->color,
            ] : null,
            'summary_count' => $paper->summaries->count(),
            'has_summary' => $paper->summaries->count() > 0 ? 1 : 0,
            'has_full_text' => $paper->hasFullText(),
            'full_text_source' => $paper->full_text_source,
            'pdf_url' => $paper->pdf_url,
            // Always include summaries for frontend to show existing summaries
            'summaries' => $paper->summaries->map(function ($s) {
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
            })->toArray(),
            // タグ情報
            'tags' => $paper->relationLoaded('tags') ? $paper->tags->map(function ($t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'color' => $t->color,
                ];
            })->toArray() : [],
        ];

        return $data;
    }
}
