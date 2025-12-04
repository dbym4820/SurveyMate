<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FetchLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'journal_id',
        'status',
        'papers_fetched',
        'new_papers',
        'error_message',
        'execution_time_ms',
    ];

    protected $casts = [
        'papers_fetched' => 'integer',
        'new_papers' => 'integer',
        'execution_time_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public static function logSuccess(string $journalId, int $papersFetched, int $newPapers, int $executionTimeMs): self
    {
        return self::create([
            'journal_id' => $journalId,
            'status' => 'success',
            'papers_fetched' => $papersFetched,
            'new_papers' => $newPapers,
            'execution_time_ms' => $executionTimeMs,
        ]);
    }

    public static function logError(string $journalId, string $errorMessage, int $executionTimeMs): self
    {
        return self::create([
            'journal_id' => $journalId,
            'status' => 'error',
            'error_message' => $errorMessage,
            'execution_time_ms' => $executionTimeMs,
        ]);
    }
}
