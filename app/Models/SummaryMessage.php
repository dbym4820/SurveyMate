<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SummaryMessage extends Model
{
    protected $fillable = [
        'summary_id',
        'user_id',
        'role',
        'content',
        'ai_provider',
        'ai_model',
        'tokens_used',
    ];

    protected $casts = [
        'tokens_used' => 'integer',
    ];

    public function summary(): BelongsTo
    {
        return $this->belongsTo(Summary::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 指定されたサマリーのメッセージ履歴を取得
     */
    public static function getHistory(int $summaryId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('summary_id', $summaryId)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }
}
