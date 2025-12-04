<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'full_name',
        'publisher',
        'rss_url',
        'category',
        'color',
        'is_active',
        'last_fetched_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_fetched_at' => 'datetime',
    ];

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
}
