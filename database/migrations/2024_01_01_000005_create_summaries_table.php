<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_id')->constrained()->onDelete('cascade')->comment('論文ID');
            $table->string('ai_provider', 50)->comment('AIプロバイダ');
            $table->string('ai_model', 100)->nullable()->comment('AIモデル');
            $table->text('summary_text')->comment('要約テキスト');
            $table->text('purpose')->nullable()->comment('研究目的');
            $table->text('methodology')->nullable()->comment('手法');
            $table->text('findings')->nullable()->comment('主な発見');
            $table->text('implications')->nullable()->comment('教育への示唆');
            $table->integer('tokens_used')->nullable()->comment('使用トークン数');
            $table->integer('generation_time_ms')->nullable()->comment('生成時間（ミリ秒）');
            $table->timestamp('created_at')->useCurrent();

            $table->index('paper_id');
            $table->index('ai_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summaries');
    }
};
