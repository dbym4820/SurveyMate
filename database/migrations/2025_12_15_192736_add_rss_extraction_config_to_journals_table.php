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
        Schema::table('journals', function (Blueprint $table) {
            // AI解析で生成したRSS抽出ルール（JSON形式）
            $table->json('rss_extraction_config')->nullable()->after('source_type');
            // 解析ステータス
            $table->string('rss_analysis_status', 20)->nullable()->after('rss_extraction_config');
            // 解析エラーメッセージ
            $table->text('rss_analysis_error')->nullable()->after('rss_analysis_status');
            // 解析日時
            $table->timestamp('rss_analyzed_at')->nullable()->after('rss_analysis_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropColumn([
                'rss_extraction_config',
                'rss_analysis_status',
                'rss_analysis_error',
                'rss_analyzed_at',
            ]);
        });
    }
};
