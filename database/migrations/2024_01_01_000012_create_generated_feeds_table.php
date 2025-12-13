<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_feeds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('ユーザーID');
            $table->string('journal_id', 191)->comment('論文誌ID');
            $table->string('source_url', 1000)->comment('論文一覧ページURL');
            $table->json('extraction_config')->nullable()->comment('AI抽出設定（セレクタ等）');
            $table->string('ai_provider', 50)->nullable()->comment('使用AIプロバイダ');
            $table->string('ai_model', 100)->nullable()->comment('使用AIモデル');
            $table->dateTime('last_generated_at')->nullable()->comment('最終解析日時');
            $table->string('generation_status', 50)->default('pending')->comment('解析ステータス');
            $table->text('error_message')->nullable()->comment('エラーメッセージ');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('journal_id')->references('id')->on('journals')->onDelete('cascade');
            $table->index('user_id');
            $table->unique('journal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_feeds');
    }
};
