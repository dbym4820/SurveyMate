<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade')->comment('ユーザーID');
            $table->string('preferred_ai_provider', 50)->default('claude')->comment('優先AIプロバイダ');
            $table->string('preferred_ai_model', 100)->nullable()->comment('優先AIモデル');
            $table->boolean('email_notifications')->default(false)->comment('メール通知');
            $table->boolean('daily_digest')->default(false)->comment('日次ダイジェスト');
            $table->json('favorite_journals')->nullable()->comment('お気に入り論文誌');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
