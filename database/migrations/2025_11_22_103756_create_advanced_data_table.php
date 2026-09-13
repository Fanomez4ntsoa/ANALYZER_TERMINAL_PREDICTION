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
        Schema::create('advanced_data', function (Blueprint $table) {
            $table->id();
            
            // Relation avec match
            $table->foreignId('match_id')->constrained()->onDelete('cascade');
            
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // DONNÉES D'ENTRÉE (ce qu'on saisit)
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            
            // Données tactiques (formations, styles)
            $table->json('tactical_data')->nullable();
            
            // Données Sofascore (forme, blessures, H2H, stats)
            $table->json('sofascore_data')->nullable();
            
            // Données FootyStats (xG, Over/Under, BTTS, séries)
            $table->json('footystats_data')->nullable();
            
            // Données FBRef (classement, top buteurs)
            $table->json('fbref_data')->nullable();
            
            // Contexte du match (importance, derby, repos)
            $table->json('context_data')->nullable();
            
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // RÉSULTATS D'ANALYSE LAYER 2
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            
            // Analyse par dimension (form, injuries, h2h, tactical, xg, context)
            $table->json('dimensions')->nullable();
            
            // Insights tactiques
            $table->json('tactical_insights')->nullable();
            
            // Recommandations originales Layer 1 (pour comparaison)
            $table->json('original_recommendations')->nullable();
            
            // Explication détaillée générée
            $table->text('detailed_explanation')->nullable();
            
            $table->timestamps();
            
            // Un match ne peut avoir qu'une entrée advanced_data
            $table->unique('match_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advanced_data');
    }
};
