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
        Schema::create('tag_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('perspective_prompt')->comment('要約の観点プロンプト');
            $table->text('summary_text')->comment('生成された要約');
            $table->string('ai_provider', 50)->comment('AIプロバイダ');
            $table->string('ai_model', 100)->nullable()->comment('AIモデル');
            $table->integer('paper_count')->comment('要約対象の論文数');
            $table->integer('tokens_used')->nullable()->comment('使用トークン数');
            $table->integer('generation_time_ms')->nullable()->comment('生成時間(ms)');
            $table->timestamps();

            $table->index(['tag_id', 'created_at']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_summaries');
    }
};
