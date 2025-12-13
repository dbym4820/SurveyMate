<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ユニーク制約を削除
        Schema::table('trend_summaries', function (Blueprint $table) {
            $table->dropUnique(['period', 'date_from', 'date_to']);
        });

        // 新しいカラムを追加
        Schema::table('trend_summaries', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id')->comment('ユーザーID');
            $table->json('tag_ids')->nullable()->after('paper_count')->comment('フィルタに使用したタグID');

            $table->index('user_id');
            $table->index('created_at');
        });

        // 外部キー制約を追加（既存データがある場合を考慮してnullable）
        Schema::table('trend_summaries', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('trend_summaries', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['user_id', 'tag_ids']);

            $table->unique(['period', 'date_from', 'date_to']);
        });
    }
};
