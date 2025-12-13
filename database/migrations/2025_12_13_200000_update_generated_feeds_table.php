<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_feeds', function (Blueprint $table) {
            // source_urlカラムを追加（存在しない場合）
            if (!Schema::hasColumn('generated_feeds', 'source_url')) {
                $table->string('source_url', 1000)->nullable()->after('journal_id')->comment('論文一覧ページURL');
            }
        });

        Schema::table('generated_feeds', function (Blueprint $table) {
            // 不要なカラムを削除
            if (Schema::hasColumn('generated_feeds', 'feed_token')) {
                $table->dropColumn('feed_token');
            }
        });

        Schema::table('generated_feeds', function (Blueprint $table) {
            if (Schema::hasColumn('generated_feeds', 'rss_xml')) {
                $table->dropColumn('rss_xml');
            }
        });
    }

    public function down(): void
    {
        Schema::table('generated_feeds', function (Blueprint $table) {
            // feed_tokenとrss_xmlを復元
            if (!Schema::hasColumn('generated_feeds', 'feed_token')) {
                $table->char('feed_token', 36)->unique()->nullable()->after('journal_id')->comment('公開RSS URL用UUID');
            }
            if (!Schema::hasColumn('generated_feeds', 'rss_xml')) {
                $table->longText('rss_xml')->nullable()->after('source_url')->comment('生成されたRSS XML');
            }
        });
    }
};
