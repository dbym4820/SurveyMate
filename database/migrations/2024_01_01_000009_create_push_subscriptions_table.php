<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('ユーザーID');
            $table->text('endpoint')->comment('Web Push エンドポイント');
            $table->string('endpoint_hash', 64)->unique()->comment('エンドポイントハッシュ');
            $table->string('p256dh_key', 500)->comment('P256DH 公開鍵');
            $table->string('auth_token', 500)->comment('認証トークン');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->timestamp('last_used_at')->nullable()->comment('最終使用日時');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
