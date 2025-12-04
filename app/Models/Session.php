<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Session extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function createForUser(User $user, int $lifetimeSeconds = 86400): self
    {
        return self::create([
            'id' => self::generateId(),
            'user_id' => $user->id,
            'expires_at' => now()->addSeconds($lifetimeSeconds),
        ]);
    }

    public static function cleanupExpired(): int
    {
        return self::where('expires_at', '<', now())->delete();
    }
}
