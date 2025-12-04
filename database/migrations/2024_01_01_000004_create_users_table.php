<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 100)->unique()->comment('ユーザー名');
            $table->string('password_hash', 255)->comment('パスワードハッシュ');
            $table->string('email', 255)->nullable()->comment('メールアドレス');
            $table->boolean('is_admin')->default(false)->comment('管理者フラグ');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->dateTime('last_login_at')->nullable()->comment('最終ログイン日時');
            $table->timestamps();

            $table->index('username');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
