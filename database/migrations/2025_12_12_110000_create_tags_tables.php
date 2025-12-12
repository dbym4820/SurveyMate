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
        // タグテーブル（ユーザーごとのタグ管理）
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100);
            $table->string('color', 50)->nullable()->default('bg-gray-500');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'name']); // 同一ユーザーで同じタグ名は不可
            $table->index('user_id');
        });

        // 論文-タグ中間テーブル
        Schema::create('paper_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('paper_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['paper_id', 'tag_id']);
            $table->foreign('paper_id')->references('id')->on('papers')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
            $table->index('tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paper_tag');
        Schema::dropIfExists('tags');
    }
};
