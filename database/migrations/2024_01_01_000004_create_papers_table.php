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
            $table->string('external_id', 255)->nullable()->comment('外部ID（RSS内でのユニークID）');
            $table->string('journal_id', 191)->comment('論文誌ID');
            $table->text('title')->comment('論文タイトル');
            $table->json('authors')->nullable()->comment('著者リスト');
            $table->text('abstract')->nullable()->comment('アブストラクト');
            $table->longText('full_text')->nullable()->comment('論文本文');
            $table->string('full_text_source', 50)->nullable()->comment('本文取得元');
            $table->dateTime('full_text_fetched_at')->nullable()->comment('本文取得日時');
            $table->string('url', 1000)->nullable()->comment('論文URL');
            $table->string('doi', 255)->nullable()->comment('DOI');
            $table->date('published_date')->nullable()->comment('公開日');
            $table->dateTime('fetched_at')->useCurrent()->comment('取得日時');
            $table->timestamps();

            $table->foreign('journal_id')->references('id')->on('journals')->onDelete('cascade');
            $table->index('journal_id');
            $table->index('published_date');
            $table->index('fetched_at');
        });

        // ユニーク制約とフルテキストインデックス
        DB::statement('ALTER TABLE papers ADD UNIQUE KEY uk_journal_title (journal_id, title(255))');
        DB::statement('ALTER TABLE papers ADD FULLTEXT INDEX ft_title_abstract (title, abstract)');
    }

    public function down(): void
    {
        Schema::dropIfExists('papers');
    }
};
