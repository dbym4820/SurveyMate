<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Summary extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'paper_id',
        'ai_provider',
        'ai_model',
        'summary_text',
        'purpose',
        'methodology',
        'findings',
        'implications',
        'tokens_used',
        'generation_time_ms',
        'created_at',
    ];

    protected $casts = [
        'tokens_used' => 'integer',
        'generation_time_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class);
    }
}
