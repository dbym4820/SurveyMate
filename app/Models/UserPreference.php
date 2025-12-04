<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'preferred_ai_provider',
        'preferred_ai_model',
        'email_notifications',
        'daily_digest',
        'favorite_journals',
    ];

    protected $casts = [
        'email_notifications' => 'boolean',
        'daily_digest' => 'boolean',
        'favorite_journals' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
