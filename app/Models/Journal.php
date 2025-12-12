<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'rss_url',
        'color',
        'is_active',
        'last_fetched_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_fetched_at' => 'datetime',
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
