<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh_key',
        'auth_token',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($subscription) {
            if (empty($subscription->endpoint_hash)) {
                $subscription->endpoint_hash = hash('sha256', $subscription->endpoint);
            }
        });

        static::updating(function ($subscription) {
            if ($subscription->isDirty('endpoint')) {
                $subscription->endpoint_hash = hash('sha256', $subscription->endpoint);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find subscription by endpoint
     */
    public static function findByEndpoint(string $endpoint)
    {
        return static::where('endpoint_hash', hash('sha256', $endpoint))->first();
    }

    /**
     * Get subscription data for Web Push
     */
    public function getSubscriptionData(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys' => [
                'p256dh' => $this->p256dh_key,
                'auth' => $this->auth_token,
            ],
        ];
    }
}
