<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->string('id', 191)->primary()->comment('自動生成ID（正式名称から生成）');
            $table->unsignedBigInteger('user_id')->nullable()->comment('所有ユーザーID');
            $table->string('name', 500)->comment('論文誌の正式名称');
            $table->string('rss_url', 500)->comment('RSSフィードURL');
            $table->enum('source_type', ['rss', 'ai_generated'])->default('rss')->comment('ソースタイプ');
            $table->string('color', 50)->default('bg-gray-500')->comment('表示色（Tailwind）');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->dateTime('last_fetched_at')->nullable()->comment('最終取得日時');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('user_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
