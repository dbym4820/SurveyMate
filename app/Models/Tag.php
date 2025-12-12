<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'color',
        'description',
    ];

    /**
     * タグの所有者
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * このタグが付いた論文
     */
    public function papers(): BelongsToMany
    {
        return $this->belongsToMany(Paper::class, 'paper_tag')
            ->withPivot('created_at');
    }

    /**
     * タグの要約一覧
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(TagSummary::class);
    }

    /**
     * 最新のタグ要約
     */
    public function latestSummary()
    {
        return $this->hasOne(TagSummary::class)->latestOfMany();
    }

    /**
     * ユーザーでスコープ
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
