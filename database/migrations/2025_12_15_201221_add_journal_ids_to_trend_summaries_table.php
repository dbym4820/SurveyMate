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
        Schema::table('trend_summaries', function (Blueprint $table) {
            $table->json('journal_ids')->nullable()->after('tag_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trend_summaries', function (Blueprint $table) {
            $table->dropColumn('journal_ids');
        });
    }
};
