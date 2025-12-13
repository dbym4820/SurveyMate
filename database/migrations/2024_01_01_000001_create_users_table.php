<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 50)->unique()->comment('ログイン用ID');
            $table->string('username', 100)->comment('表示名');
            $table->string('password_hash', 255)->comment('パスワードハッシュ');
            $table->string('email', 255)->nullable();
            $table->boolean('is_admin')->default(false)->comment('管理者フラグ');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');

            // AI設定
            $table->text('claude_api_key')->nullable()->comment('Claude APIキー（暗号化）');
            $table->text('openai_api_key')->nullable()->comment('OpenAI APIキー（暗号化）');
            $table->string('preferred_ai_provider', 50)->default('openai')->comment('優先AIプロバイダ');
            $table->string('preferred_openai_model', 100)->nullable()->comment('優先OpenAIモデル');
            $table->string('preferred_claude_model', 100)->nullable()->comment('優先Claudeモデル');

            // 研究設定
            $table->json('research_perspective')->nullable()->comment('研究観点（JSON）');
            $table->text('summary_template')->nullable()->comment('要約テンプレート');

            // 状態管理
            $table->boolean('initial_setup_completed')->default(false)->comment('初期設定完了フラグ');
            $table->dateTime('last_login_at')->nullable()->comment('最終ログイン日時');
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
