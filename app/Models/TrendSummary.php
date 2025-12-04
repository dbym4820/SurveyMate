<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrendSummary extends Model
{
    protected $fillable = [
        'period',
        'date_from',
        'date_to',
        'ai_provider',
        'ai_model',
        'overview',
        'key_topics',
        'emerging_trends',
        'category_insights',
        'recommendations',
        'paper_count',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'key_topics' => 'array',
        'emerging_trends' => 'array',
        'category_insights' => 'array',
        'recommendations' => 'array',
    ];

    /**
     * Find summary by period and date range
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
            ->first();
    }

    /**
     * Create or update summary
     *
     * @param array $data
     * @return TrendSummary
     */
    public static function createOrUpdateSummary(array $data)
    {
        return self::updateOrCreate(
            [
                'period' => $data['period'],
                'date_from' => $data['date_from'],
                'date_to' => $data['date_to'],
            ],
            [
                'ai_provider' => $data['ai_provider'],
                'ai_model' => $data['ai_model'] ?? null,
                'overview' => $data['overview'] ?? null,
                'key_topics' => $data['key_topics'] ?? null,
                'emerging_trends' => $data['emerging_trends'] ?? null,
                'category_insights' => $data['category_insights'] ?? null,
                'recommendations' => $data['recommendations'] ?? null,
                'paper_count' => $data['paper_count'] ?? 0,
            ]
        );
    }

    /**
     * Convert to API response format
     *
     * @return array
     */
    public function toApiResponse(): array
    {
        return [
            'overview' => $this->overview,
            'keyTopics' => $this->key_topics ?? [],
            'emergingTrends' => $this->emerging_trends ?? [],
            'categoryInsights' => $this->category_insights ?? [],
            'recommendations' => $this->recommendations ?? [],
        ];
    }
}
