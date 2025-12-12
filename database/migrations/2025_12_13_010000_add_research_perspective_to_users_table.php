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
        Schema::table('users', function (Blueprint $table) {
            // 調査観点設定（JSON形式で保存）
            // - research_fields: 研究分野や興味のある観点
            // - summary_perspective: 要約してほしい観点
            // - reading_focus: 論文を読む際に着目する観点
            $table->json('research_perspective')->nullable()->after('preferred_claude_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('research_perspective');
        });
    }
};
