<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GeneratedFeed extends Model
{
    protected $fillable = [
        'user_id',
        'journal_id',
        'feed_token',
        'source_url',
        'rss_xml',
        'extraction_config',
        'ai_provider',
        'ai_model',
        'last_generated_at',
        'generation_status',
        'error_message',
    ];

    protected $hidden = [
        'rss_xml',           // RSS XMLは大きいのでAPIレスポンスに含めない
        'extraction_config', // 内部設定なので非表示
    ];

    protected $casts = [
        'extraction_config' => 'array',
        'last_generated_at' => 'datetime',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // 作成時にfeed_tokenを自動生成
        static::creating(function ($feed) {
            if (empty($feed->feed_token)) {
                $feed->feed_token = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the user that owns this feed
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the journal that owns this feed
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * Scope to filter by user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('generation_status', $status);
    }

    /**
     * Check if feed generation was successful
     */
    public function isSuccess(): bool
    {
        return $this->generation_status === 'success';
    }

    /**
     * Check if feed is pending
     */
    public function isPending(): bool
    {
        return $this->generation_status === 'pending';
    }

    /**
     * Check if feed has error
     */
    public function hasError(): bool
    {
        return $this->generation_status === 'error';
    }

    /**
     * Mark feed as success
     */
    public function markAsSuccess(string $rssXml, ?array $extractionConfig = null): void
    {
        $this->update([
            'rss_xml' => $rssXml,
            'extraction_config' => $extractionConfig,
            'generation_status' => 'success',
            'last_generated_at' => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark feed as error
     */
    public function markAsError(string $errorMessage): void
    {
        $this->update([
            'generation_status' => 'error',
            'error_message' => $errorMessage,
        ]);
    }
}
