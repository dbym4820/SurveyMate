<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paper extends Model
{
    protected $fillable = [
        'external_id',
        'journal_id',
        'title',
        'authors',
        'abstract',
        'full_text',
        'full_text_source',
        'pdf_url',
        'pdf_path',
        'full_text_fetched_at',
        'url',
        'doi',
        'published_date',
        'fetched_at',
    ];

    protected $casts = [
        'authors' => 'array',
        'published_date' => 'date',
        'fetched_at' => 'datetime',
        'full_text_fetched_at' => 'datetime',
    ];

    /**
     * 本文が取得済みかどうか
     */
    public function hasFullText(): bool
    {
        return !empty($this->full_text);
    }

    /**
     * ローカルにPDFが保存されているかどうか
     */
    public function hasLocalPdf(): bool
    {
        return !empty($this->pdf_path) && \Storage::disk('papers')->exists($this->pdf_path);
    }

    /**
     * PDFのフルパスを取得
     */
    public function getPdfFullPath(): ?string
    {
        if (!$this->hasLocalPdf()) {
            return null;
        }
        return \Storage::disk('papers')->path($this->pdf_path);
    }

    /**
     * 要約に使用するテキストを取得（本文優先，なければアブストラクト）
     */
    public function getTextForSummary(): ?string
    {
        return $this->full_text ?? $this->abstract;
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    /**
     * この論文についたタグ
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'paper_tag')
            ->withPivot('created_at');
    }

    public function scopeWithJournalInfo($query)
    {
        return $query->with('journal:id,name,color');
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

    public function scopeForUser($query, $userId)
    {
        return $query->whereHas('journal', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        });
    }

    /**
     * 指定したタグIDを持つ論文をフィルタ
     */
    public function scopeWithTags($query, ?array $tagIds)
    {
        if (empty($tagIds)) {
            return $query;
        }

        return $query->whereHas('tags', function ($q) use ($tagIds) {
            $q->whereIn('tags.id', $tagIds);
        });
    }

    /**
     * タグ情報を含めて取得
     */
    public function scopeWithTagsInfo($query)
    {
        return $query->with('tags:id,name,color');
    }
}
