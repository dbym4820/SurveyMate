<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            $table->longText('full_text')->nullable()->after('abstract')
                ->comment('論文本文（PDF/HTMLから抽出）');
            $table->string('full_text_source', 50)->nullable()->after('full_text')
                ->comment('本文取得元: unpaywall_pdf, direct_pdf, html_scrape');
            $table->dateTime('full_text_fetched_at')->nullable()->after('full_text_source')
                ->comment('本文取得日時');
        });
    }

    public function down(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            $table->dropColumn(['full_text', 'full_text_source', 'full_text_fetched_at']);
        });
    }
};
