<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generated_feeds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('journal_id', 191);
            $table->char('feed_token', 36)->unique()->comment('公開RSS URL用UUID');
            $table->string('source_url', 1000)->comment('論文一覧ページURL');
            $table->longText('rss_xml')->nullable()->comment('生成されたRSS XML');
            $table->json('extraction_config')->nullable()->comment('AI抽出設定');
            $table->string('ai_provider', 50)->nullable()->comment('使用AIプロバイダー');
            $table->string('ai_model', 100)->nullable()->comment('使用モデル');
            $table->dateTime('last_generated_at')->nullable()->comment('最終生成日時');
            $table->string('generation_status', 50)->default('pending')->comment('pending/success/error');
            $table->text('error_message')->nullable()->comment('エラーメッセージ');
            $table->timestamps();

            $table->index('user_id');
            $table->unique('journal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_feeds');
    }
};
