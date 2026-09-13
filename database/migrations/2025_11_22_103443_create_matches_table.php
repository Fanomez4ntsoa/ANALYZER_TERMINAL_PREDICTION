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
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            
            // Équipes
            $table->string('home_team');
            $table->string('away_team');
            
            // Infos match
            $table->dateTime('match_date');
            $table->string('competition');
            
            // Cotes (odds)
            $table->decimal('odds_home', 6, 3);
            $table->decimal('odds_draw', 6, 3);
            $table->decimal('odds_away', 6, 3);
            
            // Résultat réel (après le match)
            $table->integer('score_home')->nullable();
            $table->integer('score_away')->nullable();
            $table->boolean('completed')->default(false);
            
            // Analyse globale
            $table->integer('global_confidence')->nullable();
            
            // Contexte détecté (JSON: type, favorite, style, description)
            $table->json('context')->nullable();
            
            // Layer 2 scores
            $table->integer('layer1_score')->nullable();
            $table->integer('layer2_score')->nullable();
            $table->enum('convergence', ['high', 'medium', 'low'])->nullable();
            
            // Validation
            $table->boolean('validated')->default(false);
            $table->timestamp('validated_at')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
