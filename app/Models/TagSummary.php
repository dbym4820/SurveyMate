<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagSummary extends Model
{
    protected $fillable = [
        'tag_id',
        'user_id',
        'perspective_prompt',
        'summary_text',
        'ai_provider',
        'ai_model',
        'paper_count',
        'tokens_used',
        'generation_time_ms',
    ];

    protected $casts = [
        'paper_count' => 'integer',
        'tokens_used' => 'integer',
        'generation_time_ms' => 'integer',
    ];

    /**
     * 関連するタグ
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * 要約を生成したユーザー
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
