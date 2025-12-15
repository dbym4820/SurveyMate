<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Journal extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'name',
        'rss_url',
        'source_type',
        'rss_extraction_config',
        'rss_analysis_status',
        'rss_analysis_error',
        'rss_analyzed_at',
        'color',
        'is_active',
        'last_fetched_at',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($journal) {
            if (empty($journal->id)) {
                $journal->id = (string) Str::ulid();
            }
        });
    }

    protected $casts = [
        'is_active' => 'boolean',
        'last_fetched_at' => 'datetime',
        'rss_extraction_config' => 'array',
        'rss_analyzed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function papers(): HasMany
    {
        return $this->hasMany(Paper::class);
    }

    public function fetchLogs(): HasMany
    {
        return $this->hasMany(FetchLog::class);
    }

    public function generatedFeed(): HasOne
    {
        return $this->hasOne(GeneratedFeed::class);
    }

    /**
     * Check if this journal uses AI-generated feed
     */
    public function isAiGenerated(): bool
    {
        return $this->source_type === 'ai_generated';
    }

    /**
     * Check if this journal has RSS extraction rules
     */
    public function hasExtractionRules(): bool
    {
        return !empty($this->rss_extraction_config)
            && $this->rss_analysis_status === 'success';
    }

    /**
     * Mark RSS analysis as pending
     */
    public function markAnalysisPending(): void
    {
        $this->update([
            'rss_analysis_status' => 'pending',
            'rss_analysis_error' => null,
        ]);
    }

    /**
     * Mark RSS analysis as success
     */
    public function markAnalysisSuccess(array $config): void
    {
        $this->update([
            'rss_extraction_config' => $config,
            'rss_analysis_status' => 'success',
            'rss_analysis_error' => null,
            'rss_analyzed_at' => now(),
        ]);
    }

    /**
     * Mark RSS analysis as failed
     */
    public function markAnalysisFailed(string $error): void
    {
        $this->update([
            'rss_analysis_status' => 'error',
            'rss_analysis_error' => $error,
            'rss_analyzed_at' => now(),
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get summary parsing configuration
     */
    public function getSummaryParsingConfig(): ?array
    {
        $config = $this->rss_extraction_config;

        if (!$config || !isset($config['summary_parsing'])) {
            return null;
        }

        return $config['summary_parsing'];
    }

    /**
     * Check if journal has summary parsing patterns
     */
    public function hasSummaryParsingPatterns(): bool
    {
        $config = $this->getSummaryParsingConfig();

        if (!$config) {
            return false;
        }

        // enabledフラグまたはパターンの存在を確認
        if (isset($config['enabled']) && !$config['enabled']) {
            return false;
        }

        return !empty($config['patterns']) || !empty($config['detected_format']);
    }

    /**
     * Update summary parsing configuration
     */
    public function updateSummaryParsingConfig(array $summaryConfig): void
    {
        $config = $this->rss_extraction_config ?? [];
        $config['summary_parsing'] = $summaryConfig;

        $this->update([
            'rss_extraction_config' => $config,
        ]);
    }

    /**
     * Clear summary parsing configuration
     */
    public function clearSummaryParsingConfig(): void
    {
        $config = $this->rss_extraction_config ?? [];

        if (isset($config['summary_parsing'])) {
            unset($config['summary_parsing']);
        }

        $this->update([
            'rss_extraction_config' => $config ?: null,
        ]);
    }
}
