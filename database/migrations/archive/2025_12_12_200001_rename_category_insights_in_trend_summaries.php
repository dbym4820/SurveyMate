<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trend_summaries', function (Blueprint $table) {
            if (Schema::hasColumn('trend_summaries', 'category_insights')) {
                $table->renameColumn('category_insights', 'journal_insights');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trend_summaries', function (Blueprint $table) {
            if (Schema::hasColumn('trend_summaries', 'journal_insights')) {
                $table->renameColumn('journal_insights', 'category_insights');
            }
        });
    }
};
