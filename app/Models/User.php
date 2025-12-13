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
        'summary_template',
        'initial_setup_completed',
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
        'initial_setup_completed' => 'boolean',
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
     * Check if user has effective Claude API key (user key or admin env key)
     *
     * @return bool
     */
    public function hasEffectiveClaudeApiKey(): bool
    {
        if ($this->claude_api_key !== null) {
            return true;
        }
        // 管理者のみ .env のキーを使用可能
        if ($this->is_admin && config('services.ai.admin_claude_api_key')) {
            return true;
        }
        return false;
    }

    /**
     * Check if user has effective OpenAI API key (user key or admin env key)
     *
     * @return bool
     */
    public function hasEffectiveOpenaiApiKey(): bool
    {
        if ($this->openai_api_key !== null) {
            return true;
        }
        // 管理者のみ .env のキーを使用可能
        if ($this->is_admin && config('services.ai.admin_openai_api_key')) {
            return true;
        }
        return false;
    }

    /**
     * Get effective Claude API key (user key or admin env key for admin users)
     *
     * @return string|null
     */
    public function getEffectiveClaudeApiKey(): ?string
    {
        if ($this->claude_api_key !== null) {
            return $this->claude_api_key;
        }
        // 管理者のみ .env のキーを使用可能
        if ($this->is_admin) {
            return config('services.ai.admin_claude_api_key');
        }
        return null;
    }

    /**
     * Get effective OpenAI API key (user key or admin env key for admin users)
     *
     * @return string|null
     */
    public function getEffectiveOpenaiApiKey(): ?string
    {
        if ($this->openai_api_key !== null) {
            return $this->openai_api_key;
        }
        // 管理者のみ .env のキーを使用可能
        if ($this->is_admin) {
            return config('services.ai.admin_openai_api_key');
        }
        return null;
    }

    /**
     * Check if Claude API key is from .env (for admin users)
     *
     * @return bool
     */
    public function isClaudeApiKeyFromEnv(): bool
    {
        return $this->is_admin
            && $this->claude_api_key === null
            && config('services.ai.admin_claude_api_key') !== null;
    }

    /**
     * Check if OpenAI API key is from .env (for admin users)
     *
     * @return bool
     */
    public function isOpenaiApiKeyFromEnv(): bool
    {
        return $this->is_admin
            && $this->openai_api_key === null
            && config('services.ai.admin_openai_api_key') !== null;
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
