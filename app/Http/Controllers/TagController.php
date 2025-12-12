<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\Tag;
use App\Models\TagSummary;
use App\Models\Paper;
use App\Services\AiSummaryService;

class TagController extends Controller
{
    public function __construct(
        private AiSummaryService $aiService
    ) {}

    /**
     * ユーザーのタグ一覧を取得
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $tags = Tag::forUser($user->id)
            ->withCount('papers')
            ->orderBy('name')
            ->get()
            ->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                    'description' => $tag->description,
                    'paper_count' => $tag->papers_count,
                ];
            });

        return response()->json([
            'success' => true,
            'tags' => $tags,
        ]);
    }

    /**
     * 新しいタグを作成
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        // 同名タグのチェック
        $existingTag = Tag::forUser($user->id)
            ->where('name', $request->name)
            ->first();

        if ($existingTag) {
            return response()->json(['error' => 'このタグ名は既に存在します'], 400);
        }

        $tag = Tag::create([
            'user_id' => $user->id,
            'name' => $request->name,
            'color' => $request->color ?? 'bg-gray-500',
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'タグを作成しました',
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
                'description' => $tag->description,
                'paper_count' => 0,
            ],
        ], 201);
    }

    /**
     * タグを更新
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $tag = Tag::forUser($user->id)->find($id);

        if (!$tag) {
            return response()->json(['error' => 'タグが見つかりません'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        // 同名タグのチェック（自分以外）
        if ($request->name && $request->name !== $tag->name) {
            $existingTag = Tag::forUser($user->id)
                ->where('name', $request->name)
                ->where('id', '!=', $id)
                ->first();

            if ($existingTag) {
                return response()->json(['error' => 'このタグ名は既に存在します'], 400);
            }
        }

        $updateData = [];
        if ($request->has('name')) $updateData['name'] = $request->name;
        if ($request->has('color')) $updateData['color'] = $request->color;
        if ($request->has('description')) $updateData['description'] = $request->description;

        $tag->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'タグを更新しました',
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
                'description' => $tag->description,
            ],
        ]);
    }

    /**
     * タグを削除
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('user');

        $tag = Tag::forUser($user->id)->find($id);

        if (!$tag) {
            return response()->json(['error' => 'タグが見つかりません'], 404);
        }

        $tag->delete();

        return response()->json([
            'success' => true,
            'message' => 'タグを削除しました',
        ]);
    }

    /**
     * 論文にタグを追加
     */
    public function addTagToPaper(Request $request, int $paperId): JsonResponse
    {
        $user = $request->attributes->get('user');

        // 論文がユーザーのものか確認
        $paper = Paper::forUser($user->id)->find($paperId);
        if (!$paper) {
            return response()->json(['error' => '論文が見つかりません'], 404);
        }

        $validator = Validator::make($request->all(), [
            'tag_id' => 'required_without:tag_name|integer',
            'tag_name' => 'required_without:tag_id|string|max:100',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        // tag_idが指定されていればそのタグを使用，なければtag_nameで検索または作成
        if ($request->tag_id) {
            $tag = Tag::forUser($user->id)->find($request->tag_id);
            if (!$tag) {
                return response()->json(['error' => 'タグが見つかりません'], 404);
            }
        } else {
            $tag = Tag::forUser($user->id)
                ->where('name', $request->tag_name)
                ->first();

            if (!$tag) {
                // 新しいタグを作成
                $tag = Tag::create([
                    'user_id' => $user->id,
                    'name' => $request->tag_name,
                    'color' => $request->color ?? 'bg-gray-500',
                    'description' => $request->description,
                ]);
            }
        }

        // 既に付いているかチェック
        if ($paper->tags()->where('tags.id', $tag->id)->exists()) {
            return response()->json([
                'success' => true,
                'message' => 'タグは既に付いています',
                'tag' => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                    'description' => $tag->description,
                ],
            ]);
        }

        $paper->tags()->attach($tag->id);

        return response()->json([
            'success' => true,
            'message' => 'タグを追加しました',
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
                'description' => $tag->description,
            ],
        ]);
    }

    /**
     * 論文からタグを削除
     */
    public function removeTagFromPaper(Request $request, int $paperId, int $tagId): JsonResponse
    {
        $user = $request->attributes->get('user');

        // 論文がユーザーのものか確認
        $paper = Paper::forUser($user->id)->find($paperId);
        if (!$paper) {
            return response()->json(['error' => '論文が見つかりません'], 404);
        }

        // タグがユーザーのものか確認
        $tag = Tag::forUser($user->id)->find($tagId);
        if (!$tag) {
            return response()->json(['error' => 'タグが見つかりません'], 404);
        }

        $paper->tags()->detach($tagId);

        return response()->json([
            'success' => true,
            'message' => 'タグを削除しました',
        ]);
    }

    /**
     * 特定のタグが付いた論文一覧を取得
     */
    public function papersByTag(Request $request, int $tagId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $tag = Tag::forUser($user->id)->find($tagId);
        if (!$tag) {
            return response()->json(['error' => 'タグが見つかりません'], 404);
        }

        $papers = $tag->papers()
            ->with('journal:id,name,color')
            ->with('tags:id,name,color')
            ->with(['summaries' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(1);
            }])
            ->orderBy('published_date', 'desc')
            ->get()
            ->map(function ($paper) {
                $latestSummary = $paper->summaries->first();
                return [
                    'id' => $paper->id,
                    'title' => $paper->title,
                    'authors' => $paper->authors,
                    'abstract' => $paper->abstract,
                    'url' => $paper->url,
                    'doi' => $paper->doi,
                    'published_date' => $paper->published_date?->format('Y-m-d'),
                    'journal_name' => $paper->journal?->name,
                    'journal_color' => $paper->journal?->color,
                    'has_summary' => $latestSummary !== null,
                    'has_full_text' => !empty($paper->full_text),
                    'tags' => $paper->tags->map(fn($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'color' => $t->color,
                    ]),
                    'summaries' => $latestSummary ? [[
                        'id' => $latestSummary->id,
                        'summary_text' => $latestSummary->summary_text,
                        'purpose' => $latestSummary->purpose,
                        'methodology' => $latestSummary->methodology,
                        'findings' => $latestSummary->findings,
                        'implications' => $latestSummary->implications,
                        'ai_provider' => $latestSummary->ai_provider,
                        'ai_model' => $latestSummary->ai_model,
                        'created_at' => $latestSummary->created_at?->toISOString(),
                    ]] : [],
                ];
            });

        // 最新のタグ要約を取得
        $latestTagSummary = TagSummary::where('tag_id', $tagId)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
                'description' => $tag->description,
            ],
            'papers' => $papers,
            'latest_summary' => $latestTagSummary ? [
                'id' => $latestTagSummary->id,
                'perspective_prompt' => $latestTagSummary->perspective_prompt,
                'summary_text' => $latestTagSummary->summary_text,
                'ai_provider' => $latestTagSummary->ai_provider,
                'ai_model' => $latestTagSummary->ai_model,
                'paper_count' => $latestTagSummary->paper_count,
                'tokens_used' => $latestTagSummary->tokens_used,
                'generation_time_ms' => $latestTagSummary->generation_time_ms,
                'created_at' => $latestTagSummary->created_at->toISOString(),
            ] : null,
        ]);
    }

    /**
     * タググループの要約を生成
     */
    public function generateSummary(Request $request, int $tagId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $tag = Tag::forUser($user->id)->find($tagId);
        if (!$tag) {
            return response()->json(['error' => 'タグが見つかりません'], 404);
        }

        $validator = Validator::make($request->all(), [
            'perspective_prompt' => 'required|string|max:1000',
            'provider' => 'nullable|string|in:openai,claude',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        // タグに紐づく論文を取得
        $papers = $tag->papers()
            ->with('journal:id,name,color')
            ->orderBy('published_date', 'desc')
            ->limit(30) // トークン制限のため最大30件
            ->get()
            ->map(function ($paper) {
                return [
                    'id' => $paper->id,
                    'title' => $paper->title,
                    'authors' => $paper->authors,
                    'abstract' => $paper->abstract,
                    'published_date' => $paper->published_date?->format('Y-m-d'),
                    'journal_name' => $paper->journal?->name ?? '不明',
                ];
            })
            ->toArray();

        if (count($papers) === 0) {
            return response()->json(['error' => 'このタグには論文がありません'], 400);
        }

        try {
            $this->aiService->setUser($user);
            $result = $this->aiService->generateTagSummary(
                $tag,
                $papers,
                $request->perspective_prompt,
                $request->provider
            );

            // 要約をデータベースに保存
            $tagSummary = TagSummary::create([
                'tag_id' => $tag->id,
                'user_id' => $user->id,
                'perspective_prompt' => $request->perspective_prompt,
                'summary_text' => $result['summary_text'],
                'ai_provider' => $result['provider'],
                'ai_model' => $result['model'],
                'paper_count' => $result['paper_count'],
                'tokens_used' => $result['tokens_used'] ?? null,
                'generation_time_ms' => $result['generation_time_ms'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'summary' => [
                    'id' => $tagSummary->id,
                    'perspective_prompt' => $tagSummary->perspective_prompt,
                    'summary_text' => $tagSummary->summary_text,
                    'ai_provider' => $tagSummary->ai_provider,
                    'ai_model' => $tagSummary->ai_model,
                    'paper_count' => $tagSummary->paper_count,
                    'tokens_used' => $tagSummary->tokens_used,
                    'generation_time_ms' => $tagSummary->generation_time_ms,
                    'created_at' => $tagSummary->created_at->toISOString(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'タグ要約の生成に失敗しました: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * タグの要約一覧を取得
     */
    public function getSummaries(Request $request, int $tagId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $tag = Tag::forUser($user->id)->find($tagId);
        if (!$tag) {
            return response()->json(['error' => 'タグが見つかりません'], 404);
        }

        $summaries = TagSummary::where('tag_id', $tagId)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($summary) {
                return [
                    'id' => $summary->id,
                    'perspective_prompt' => $summary->perspective_prompt,
                    'summary_text' => $summary->summary_text,
                    'ai_provider' => $summary->ai_provider,
                    'ai_model' => $summary->ai_model,
                    'paper_count' => $summary->paper_count,
                    'tokens_used' => $summary->tokens_used,
                    'generation_time_ms' => $summary->generation_time_ms,
                    'created_at' => $summary->created_at->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ],
            'summaries' => $summaries,
        ]);
    }

    /**
     * タグ要約を削除
     */
    public function deleteSummary(Request $request, int $tagId, int $summaryId): JsonResponse
    {
        $user = $request->attributes->get('user');

        $tag = Tag::forUser($user->id)->find($tagId);
        if (!$tag) {
            return response()->json(['error' => 'タグが見つかりません'], 404);
        }

        $summary = TagSummary::where('tag_id', $tagId)
            ->where('user_id', $user->id)
            ->find($summaryId);

        if (!$summary) {
            return response()->json(['error' => '要約が見つかりません'], 404);
        }

        $summary->delete();

        return response()->json([
            'success' => true,
            'message' => '要約を削除しました',
        ]);
    }
}
