<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;

class User extends Model
{
    protected $fillable = [
        'user_id',      // ログイン用ID（ユニーク）
        'username',     // 表示名（重複可）
        'password',
        'password_hash',
        'email',
        'is_admin',
        'is_active',
        'last_login_at',
        'claude_api_key',
        'openai_api_key',
        'preferred_ai_provider',
        'preferred_openai_model',
        'preferred_claude_model',
        'research_perspective',
    ];

    protected $hidden = [
        'password_hash',
        'claude_api_key',
        'openai_api_key',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'research_perspective' => 'array',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    public function setPasswordAttribute(string $password): void
    {
        $this->attributes['password_hash'] = Hash::make($password);
    }

    public function verifyPassword(string $password): bool
    {
        return Hash::check($password, $this->password_hash);
    }

    /**
     * Set Claude API key (encrypted)
     *
     * @param string|null $value
     * @return void
     */
    public function setClaudeApiKeyAttribute($value)
    {
        if ($value === null || $value === '') {
            $this->attributes['claude_api_key'] = null;
        } else {
            $this->attributes['claude_api_key'] = Crypt::encryptString($value);
        }
    }

    /**
     * Get Claude API key (decrypted)
     *
     * @param string|null $value
     * @return string|null
     */
    public function getClaudeApiKeyAttribute($value)
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Set OpenAI API key (encrypted)
     *
     * @param string|null $value
     * @return void
     */
    public function setOpenaiApiKeyAttribute($value)
    {
        if ($value === null || $value === '') {
            $this->attributes['openai_api_key'] = null;
        } else {
            $this->attributes['openai_api_key'] = Crypt::encryptString($value);
        }
    }

    /**
     * Get OpenAI API key (decrypted)
     *
     * @param string|null $value
     * @return string|null
     */
    public function getOpenaiApiKeyAttribute($value)
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if user has Claude API key configured
     *
     * @return bool
     */
    public function hasClaudeApiKey(): bool
    {
        return $this->claude_api_key !== null;
    }

    /**
     * Check if user has OpenAI API key configured
     *
     * @return bool
     */
    public function hasOpenaiApiKey(): bool
    {
        return $this->openai_api_key !== null;
    }

    /**
     * Get available AI providers for this user
     *
     * @return array
     */
    public function getAvailableAiProviders(): array
    {
        $providers = [];
        if ($this->hasClaudeApiKey()) {
            $providers[] = 'claude';
        }
        if ($this->hasOpenaiApiKey()) {
            $providers[] = 'openai';
        }
        return $providers;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
