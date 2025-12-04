<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddApiKeysToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted API keys (stored as TEXT for encrypted data)
            $table->text('claude_api_key')->nullable()->after('is_active');
            $table->text('openai_api_key')->nullable()->after('claude_api_key');
            // User's preferred AI provider
            $table->string('preferred_ai_provider', 20)->default('claude')->after('openai_api_key');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['claude_api_key', 'openai_api_key', 'preferred_ai_provider']);
        });
    }
}
