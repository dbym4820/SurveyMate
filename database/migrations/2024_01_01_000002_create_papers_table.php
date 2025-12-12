<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('papers', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 255)->nullable()->comment('外部ID（DOIなど）');
            $table->string('journal_id', 1000)->comment('論文誌ID');
            $table->text('title')->comment('タイトル');
            $table->json('authors')->nullable()->comment('著者リスト');
            $table->text('abstract')->nullable()->comment('アブストラクト');
            $table->string('url', 1000)->nullable()->comment('論文URL');
            $table->string('doi', 255)->nullable()->comment('DOI');
            $table->date('published_date')->nullable()->comment('公開日');
            $table->dateTime('fetched_at')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('取得日時');
            $table->timestamps();

            $table->index('fetched_at');
        });

        // プレフィックスインデックスでjournal_idのインデックスを追加（MySQL制限回避）
        DB::statement('ALTER TABLE papers ADD INDEX idx_journal_id (journal_id(191))');
        DB::statement('ALTER TABLE papers ADD INDEX idx_published_date (published_date)');
        // Add unique key and fulltext index using raw SQL
        DB::statement('ALTER TABLE papers ADD UNIQUE KEY uk_journal_title (journal_id(191), title(255))');
        DB::statement('ALTER TABLE papers ADD FULLTEXT INDEX ft_title_abstract (title, abstract)');
    }

    public function down(): void
    {
        Schema::dropIfExists('papers');
    }
};
