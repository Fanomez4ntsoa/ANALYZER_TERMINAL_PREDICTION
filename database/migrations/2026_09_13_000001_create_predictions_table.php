<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table `predictions` : sortie brute du modèle probabiliste, une ligne par
     * (match, marché, issue). Remplace `recommendations` dans le flux.
     * La table `recommendations` est laissée en place (données historiques).
     */
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();

            // Marché : winner | doubleChance | overUnder25 | btts
            $table->string('market', 32);
            // Issue : 1 | X | 2 | 1X | X2 | 12 | Over | Under | Yes | No
            $table->string('outcome', 16);

            // Probabilité du modèle (Poisson / xG), en décimal 0..1
            $table->decimal('model_probability', 6, 5);

            // Cote du bookmaker configuré au moment du calcul (null si absente)
            $table->decimal('odds', 7, 3)->nullable();
            // 1 / cote, marge incluse (seuil réel à battre)
            $table->decimal('implied_probability', 6, 5)->nullable();
            // Probabilité implicite après retrait de la marge (ensemble de marché normalisé à 1)
            $table->decimal('fair_probability', 6, 5)->nullable();
            // model_probability - fair_probability
            $table->decimal('edge', 7, 5)->nullable();

            // Traçabilité de la cote
            $table->string('bookmaker', 64)->nullable();
            $table->timestamp('odds_taken_at')->nullable();

            // Horodatage du calcul
            $table->timestamp('computed_at');

            $table->timestamps();

            $table->unique(['match_id', 'market', 'outcome']);
            $table->index(['market', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
