<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // HCIMLab SSO (OIDC) の sub クレーム．不変IDなのでこれで紐付ける
            $table->string('sso_sub', 64)->nullable()->unique()->after('user_id')->comment('SSO (OIDC) subject ID');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_sub']);
            $table->dropColumn('sso_sub');
        });
    }
};
