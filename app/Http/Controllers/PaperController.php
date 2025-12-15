<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\Paper;
use App\Services\QueueRunnerService;
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
        // Use COALESCE to handle NULL published_date (AI-generated papers may not have dates)
        $papers = $query->orderByRaw('COALESCE(published_date, DATE(fetched_at)) DESC')
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
            'has_local_pdf' => $paper->hasLocalPdf(),
            'full_text_fetched_at' => $paper->full_text_fetched_at?->toISOString(),
        ]);
    }

    /**
     * ローカルに保存されたPDFをダウンロード
     */
    public function downloadPdf(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $user = $request->attributes->get('user');

        $paper = Paper::forUser($user->id)->find($id);

        if (!$paper) {
            return response()->json(['success' => false, 'error' => '論文が見つかりません'], 404);
        }

        if (!$paper->hasLocalPdf()) {
            return response()->json(['success' => false, 'error' => 'PDFが保存されていません'], 404);
        }

        $disk = Storage::disk('papers');
        $path = $paper->pdf_path;

        // ファイル名を生成（DOIまたはタイトルから）
        $fileName = $paper->doi
            ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $paper->doi) . '.pdf'
            : preg_replace('/[^a-zA-Z0-9._\-\s]/', '', substr($paper->title, 0, 50)) . '.pdf';

        return $disk->download($path, $fileName, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
        ]);
    }

    /**
     * PDF処理状況を確認し、必要に応じてワーカーを再起動
     * 各論文のpdf_statusも返す
     */
    public function processingStatus(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        // ユーザーの論文でPDF処理に関係するもの（pending/processing/completed/failed）のIDとステータスを取得
        $paperStatuses = Paper::forUser($user->id)
            ->whereNotNull('pdf_status')
            ->select('id', 'pdf_status', 'pdf_path')
            ->get()
            ->map(function ($paper) {
                return [
                    'id' => $paper->id,
                    'pdf_status' => $paper->pdf_status,
                    'has_local_pdf' => !empty($paper->pdf_path),
                ];
            });

        $processingCount = $paperStatuses->whereIn('pdf_status', ['pending', 'processing'])->count();

        // キュー内のジョブ数
        $pendingJobs = QueueRunnerService::getPendingJobCount('pdf-processing');

        // ワーカーの状態
        $workerRunning = QueueRunnerService::isWorkerRunning('pdf-processing');
        $workerStarted = false;

        // ジョブがある場合は常にワーカー起動を試みる
        if ($pendingJobs > 0) {
            $workerStarted = QueueRunnerService::startWorkerIfNeeded('pdf-processing');
        }

        return response()->json([
            'success' => true,
            'processing_count' => $processingCount,
            'pending_jobs' => $pendingJobs,
            'worker_running' => $workerRunning || $workerStarted,
            'worker_started' => $workerStarted,
            'paper_statuses' => $paperStatuses,
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
            'has_local_pdf' => $paper->hasLocalPdf(),
            'pdf_status' => $paper->pdf_status,
            // Always include summaries for frontend to show existing summaries
            'summaries' => $paper->summaries->map(function ($s) {
                return [
                    'id' => $s->id,
                    'ai_provider' => $s->ai_provider,
                    'ai_model' => $s->ai_model,
                    'input_source' => $s->input_source,
                    'input_source_label' => $this->getInputSourceLabel($s->input_source),
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

    /**
     * 入力ソースの日本語ラベルを取得
     */
    private function getInputSourceLabel(?string $source): string
    {
        return match ($source) {
            'pdf' => 'PDF本文',
            'pdf_fetched' => 'PDF本文（DOI経由で取得）',
            'full_text' => '本文テキスト',
            'doi_fetch' => 'DOIページから取得したテキスト',
            'abstract' => 'アブストラクトのみ',
            'minimal' => 'タイトル・メタデータのみ（※推測を含む可能性あり）',
            default => '不明',
        };
    }
}
