<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fetch_logs', function (Blueprint $table) {
            $table->id();
            $table->string('journal_id', 1000)->nullable()->comment('論文誌ID');
            $table->enum('status', ['success', 'error', 'partial'])->comment('ステータス');
            $table->integer('papers_fetched')->default(0)->comment('取得論文数');
            $table->integer('new_papers')->default(0)->comment('新規論文数');
            $table->text('error_message')->nullable()->comment('エラーメッセージ');
            $table->integer('execution_time_ms')->nullable()->comment('実行時間（ミリ秒）');
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
            $table->index('created_at');
        });

        // プレフィックスインデックスでjournal_idのインデックスを追加（MySQL制限回避）
        DB::statement('ALTER TABLE fetch_logs ADD INDEX idx_journal_id (journal_id(191))');
    }

    public function down(): void
    {
        Schema::dropIfExists('fetch_logs');
    }
};
