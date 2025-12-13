<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('period', 20)->comment('期間種別（day/week/month/halfyear）');
            $table->date('date_from')->comment('期間開始日');
            $table->date('date_to')->comment('期間終了日');
            $table->string('ai_provider', 50)->comment('AIプロバイダ');
            $table->string('ai_model', 100)->nullable()->comment('AIモデル');
            $table->text('overview')->nullable()->comment('概要');
            $table->json('key_topics')->nullable()->comment('主要トピック');
            $table->json('emerging_trends')->nullable()->comment('新興トレンド');
            $table->json('journal_insights')->nullable()->comment('論文誌別洞察');
            $table->json('recommendations')->nullable()->comment('推奨事項');
            $table->integer('paper_count')->default(0)->comment('対象論文数');
            $table->timestamps();

            $table->index(['period', 'date_from']);
            $table->unique(['period', 'date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_summaries');
    }
};
