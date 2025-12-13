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
            $table->boolean('initial_setup_completed')
                  ->default(false)
                  ->after('summary_template')
                  ->comment('初期設定完了フラグ');
        });

        // 既存ユーザーは完了済みとして扱う
        DB::table('users')->update(['initial_setup_completed' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('initial_setup_completed');
        });
    }
};
