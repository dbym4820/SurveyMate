<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paper extends Model
{
    protected $fillable = [
        'external_id',
        'journal_id',
        'title',
        'authors',
        'abstract',
        'url',
        'doi',
        'published_date',
        'fetched_at',
    ];

    protected $casts = [
        'authors' => 'array',
        'published_date' => 'date',
        'fetched_at' => 'datetime',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    public function scopeWithJournalInfo($query)
    {
        return $query->with('journal:id,name,full_name,color,category');
    }

    public function scopeWithSummaries($query)
    {
        return $query->with('summaries');
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->whereRaw(
            "MATCH(title, abstract) AGAINST(? IN BOOLEAN MODE)",
            [$search . '*']
        );
    }

    public function scopeInJournals($query, ?array $journalIds)
    {
        if (empty($journalIds)) {
            return $query;
        }

        return $query->whereIn('journal_id', $journalIds);
    }

    public function scopePublishedBetween($query, ?string $dateFrom, ?string $dateTo)
    {
        if ($dateFrom) {
            $query->where('published_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('published_date', '<=', $dateTo);
        }

        return $query;
    }
}
