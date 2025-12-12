<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * .envで指定された管理者ユーザーを作成
     */
    public function up(): void
    {
        $adminUserId = env('ADMIN_USER_ID');
        $adminUsername = env('ADMIN_USERNAME');
        $adminPassword = env('ADMIN_PASSWORD');
        $adminEmail = env('ADMIN_EMAIL');

        // 必須項目が設定されていない場合はスキップ
        if (empty($adminUserId) || empty($adminUsername) || empty($adminPassword)) {
            return;
        }

        // 既存ユーザーがいなければ作成
        $exists = DB::table('users')->where('user_id', $adminUserId)->exists();
        if (!$exists) {
            DB::table('users')->insert([
                'user_id' => $adminUserId,
                'username' => $adminUsername,
                'password_hash' => Hash::make($adminPassword),
                'email' => $adminEmail,
                'is_admin' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $adminUserId = env('ADMIN_USER_ID');
        if (!empty($adminUserId)) {
            DB::table('users')->where('user_id', $adminUserId)->delete();
        }
    }
};
