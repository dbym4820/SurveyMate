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
            $table->string('name', 100)->comment('略称');
            $table->string('full_name', 255)->comment('正式名称');
            $table->string('publisher', 100)->comment('出版社');
            $table->string('rss_url', 500)->comment('RSSフィードURL');
            $table->string('category', 100)->nullable()->comment('カテゴリ');
            $table->string('color', 50)->default('bg-gray-500')->comment('表示色（Tailwind）');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->dateTime('last_fetched_at')->nullable()->comment('最終取得日時');
            $table->timestamps();

            $table->index('is_active');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
