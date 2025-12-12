<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\Tag;
use App\Models\Paper;

class TagController extends Controller
{
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
            ->orderBy('published_date', 'desc')
            ->get()
            ->map(function ($paper) {
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
                    'tags' => $paper->tags->map(fn($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'color' => $t->color,
                    ]),
                ];
            });

        return response()->json([
            'success' => true,
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
                'description' => $tag->description,
            ],
            'papers' => $papers,
        ]);
    }
}
