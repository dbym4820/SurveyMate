<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            // PDF処理ステータス: pending, processing, completed, failed, null(未処理)
            $table->string('pdf_status', 20)->nullable()->after('pdf_path')->comment('PDF処理ステータス');
            $table->index('pdf_status');
        });
    }

    public function down(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            $table->dropIndex(['pdf_status']);
            $table->dropColumn('pdf_status');
        });
    }
};
