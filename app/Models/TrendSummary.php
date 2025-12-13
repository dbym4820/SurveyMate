<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrendSummary extends Model
{
    protected $fillable = [
        'user_id',
        'period',
        'date_from',
        'date_to',
        'ai_provider',
        'ai_model',
        'overview',
        'key_topics',
        'emerging_trends',
        'journal_insights',
        'recommendations',
        'paper_count',
        'tag_ids',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'key_topics' => 'array',
        'emerging_trends' => 'array',
        'journal_insights' => 'array',
        'recommendations' => 'array',
        'tag_ids' => 'array',
    ];

    /**
     * Get the user that owns this trend summary
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Find latest summary by period for a user
     *
     * @param int $userId
     * @param string $period
     * @param array|null $tagIds
     * @return TrendSummary|null
     */
    public static function findLatestForUser(int $userId, string $period, ?array $tagIds = null)
    {
        $query = self::where('user_id', $userId)
            ->where('period', $period)
            ->orderBy('created_at', 'desc');

        if ($tagIds !== null && count($tagIds) > 0) {
            // JSONカラムで完全一致検索
            $query->whereJsonContains('tag_ids', $tagIds);
        } else {
            $query->where(function ($q) {
                $q->whereNull('tag_ids')
                  ->orWhere('tag_ids', '[]');
            });
        }

        return $query->first();
    }

    /**
     * Get history for a user
     *
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getHistoryForUser(int $userId, int $limit = 20)
    {
        return self::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Find summary by period and date range (legacy, for backwards compatibility)
     *
     * @param string $period
     * @param string $dateFrom
     * @param string $dateTo
     * @return TrendSummary|null
     */
    public static function findByPeriodAndDate(string $period, string $dateFrom, string $dateTo)
    {
        return self::where('period', $period)
            ->where('date_from', $dateFrom)
            ->where('date_to', $dateTo)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Create a new summary (always creates new record for history)
     *
     * @param array $data
     * @return TrendSummary
     */
    public static function createSummary(array $data)
    {
        return self::create([
            'user_id' => $data['user_id'] ?? null,
            'period' => $data['period'],
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'ai_provider' => $data['ai_provider'],
            'ai_model' => $data['ai_model'] ?? null,
            'overview' => $data['overview'] ?? null,
            'key_topics' => $data['key_topics'] ?? null,
            'emerging_trends' => $data['emerging_trends'] ?? null,
            'journal_insights' => $data['journal_insights'] ?? null,
            'recommendations' => $data['recommendations'] ?? null,
            'paper_count' => $data['paper_count'] ?? 0,
            'tag_ids' => $data['tag_ids'] ?? null,
        ]);
    }

    /**
     * Convert to API response format
     *
     * @return array
     */
    public function toApiResponse(): array
    {
        return [
            'id' => $this->id,
            'overview' => $this->overview,
            'keyTopics' => $this->key_topics ?? [],
            'emergingTrends' => $this->emerging_trends ?? [],
            'journalInsights' => $this->journal_insights ?? [],
            'recommendations' => $this->recommendations ?? [],
            'period' => $this->period,
            'dateFrom' => $this->date_from?->format('Y-m-d'),
            'dateTo' => $this->date_to?->format('Y-m-d'),
            'paperCount' => $this->paper_count,
            'tagIds' => $this->tag_ids ?? [],
            'provider' => $this->ai_provider,
            'model' => $this->ai_model,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
