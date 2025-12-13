<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 要約テンプレート（AIに送信する要約形式の指示）
            $table->text('summary_template')->nullable()->after('research_perspective');
        });

        // .envのデフォルト設定を管理者ユーザーに適用
        $adminUserId = env('ADMIN_USER_ID');
        $summaryTemplate = env('DEFAULT_SUMMARY_TEMPLATE', '');

        if (!empty($adminUserId) && !empty($summaryTemplate)) {
            DB::table('users')
                ->where('user_id', $adminUserId)
                ->update(['summary_template' => $summaryTemplate]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('summary_template');
        });
    }
};
