<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Check if category column exists before dropping
        if (Schema::hasColumn('journals', 'category')) {
            Schema::table('journals', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('journals', 'category')) {
            Schema::table('journals', function (Blueprint $table) {
                $table->string('category', 100)->nullable()->after('rss_url')->comment('カテゴリ');
            });
        }
    }
};
