<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            // スタック回復時の再ディスパッチ回数（上限到達でfailed確定）
            $table->unsignedTinyInteger('pdf_fetch_attempts')->default(0)->after('pdf_status');
        });
    }

    public function down(): void
    {
        Schema::table('papers', function (Blueprint $table) {
            $table->dropColumn('pdf_fetch_attempts');
        });
    }
};
