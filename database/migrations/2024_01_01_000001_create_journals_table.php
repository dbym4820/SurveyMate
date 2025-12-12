<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->unsignedBigInteger('user_id')->nullable()->comment('所有ユーザーID');
            $table->string('name', 255)->comment('論文誌名');
            $table->string('rss_url', 500)->comment('RSSフィードURL');
            $table->string('color', 50)->default('bg-gray-500')->comment('表示色（Tailwind）');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->dateTime('last_fetched_at')->nullable()->comment('最終取得日時');
            $table->timestamps();

            $table->index('user_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
