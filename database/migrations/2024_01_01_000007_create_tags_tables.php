<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tags テーブル
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('ユーザーID');
            $table->string('name', 100)->comment('タグ名');
            $table->string('color', 50)->nullable()->comment('表示色');
            $table->text('description')->nullable()->comment('説明');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index('user_id');
        });

        // paper_tag 中間テーブル
        Schema::create('paper_tag', function (Blueprint $table) {
            $table->foreignId('paper_id')->constrained()->onDelete('cascade');
            $table->foreignId('tag_id')->constrained()->onDelete('cascade');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['paper_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_tag');
        Schema::dropIfExists('tags');
    }
};
