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
        Schema::create('ai_analysis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();

            // Sorties des agents (JSON)
            $table->json('value_output')->nullable();
            $table->json('risk_output')->nullable();
            $table->json('market_output')->nullable();
            $table->json('narrative_output')->nullable();
            $table->json('final_decision')->nullable();

            // Score IA agrégé (0-100)
            $table->unsignedTinyInteger('ai_score')->nullable();

            // Comparaison avec le résultat réel (rempli après le match)
            $table->json('ai_vs_real_result')->nullable();

            // Coût API en tokens
            $table->unsignedInteger('total_input_tokens')->default(0);
            $table->unsignedInteger('total_output_tokens')->default(0);

            $table->timestamps();

            $table->unique('match_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_analysis');
    }
};
