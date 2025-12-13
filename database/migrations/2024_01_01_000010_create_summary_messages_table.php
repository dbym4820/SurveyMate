<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summary_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('summary_id')->constrained()->onDelete('cascade')->comment('要約ID');
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->comment('ユーザーID');
            $table->enum('role', ['user', 'assistant'])->comment('メッセージ役割');
            $table->text('content')->comment('メッセージ内容');
            $table->string('ai_provider', 50)->nullable()->comment('AIプロバイダ');
            $table->string('ai_model', 100)->nullable()->comment('AIモデル');
            $table->integer('tokens_used')->nullable()->comment('使用トークン数');
            $table->timestamps();

            $table->index(['summary_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_messages');
    }
};
