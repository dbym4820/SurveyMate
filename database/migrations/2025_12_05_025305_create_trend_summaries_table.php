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
        Schema::create('trend_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('period', 20); // day, week, month, halfyear
            $table->date('date_from');
            $table->date('date_to');
            $table->string('ai_provider', 50);
            $table->string('ai_model', 100)->nullable();
            $table->text('overview')->nullable();
            $table->json('key_topics')->nullable();
            $table->json('emerging_trends')->nullable();
            $table->json('category_insights')->nullable();
            $table->json('recommendations')->nullable();
            $table->integer('paper_count')->default(0);
            $table->timestamps();

            // Index for efficient lookups
            $table->index(['period', 'date_from']);
            $table->unique(['period', 'date_from', 'date_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trend_summaries');
    }
};
