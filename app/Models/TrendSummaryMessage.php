<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrendSummaryMessage extends Model
{
    protected $fillable = [
        'trend_summary_id',
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

    public function trendSummary(): BelongsTo
    {
        return $this->belongsTo(TrendSummary::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
