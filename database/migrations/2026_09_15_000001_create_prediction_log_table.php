<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des sélections : une ligne figée par prédiction, écrite au moment du
     * calcul, jamais modifiée ensuite sauf pour sa clôture (colonnes de clôture
     * nulles, écrites une seule fois). Contrairement à `predictions`, remplacée à
     * chaque recalcul, le journal ajoute une ligne à chaque calcul.
     *
     * Seules les lignes avec une cote d'un bookmaker identifié (jamais legacy_max)
     * et une probabilité équitable y entrent. Aucun remplissage rétroactif.
     *
     * - match_id : suppression du match refusée, le journal ne disparaît jamais
     *   avec lui. Non vidé par app:reset.
     * - league_id, home_team, away_team, kickoff_at : copies au moment du calcul ;
     *   la clôture vérifie que le match est toujours le même (reprogrammé, base
     *   remise à zéro).
     * - trigger : pipeline (predictions:compute, tous les matchs d'une date) ou
     *   manual (bouton Calculer). Le rapport ne lit que le premier calcul du
     *   pipeline, pour ne dépendre en rien de ce que l'utilisateur consulte.
     * - closing_edge : cote Bet365 × probabilité équitable Pinnacle à la clôture − 1.
     *   Distinct du CLV Pinnacle contre Pinnacle de /market (docs/decisions.md).
     *
     * Horodatages nullables : sous MariaDB sans explicit_defaults_for_timestamp, un
     * premier TIMESTAMP NOT NULL reçoit ON UPDATE CURRENT_TIMESTAMP, et la clôture
     * réécrirait computed_at. Le modèle impose leur présence à la création.
     */
    public function up(): void
    {
        Schema::create('prediction_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->restrictOnDelete();

            $table->unsignedInteger('league_id')->nullable();
            $table->string('home_team');
            $table->string('away_team');
            $table->timestamp('kickoff_at')->nullable();
            $table->string('trigger', 16);

            $table->string('market', 32);
            $table->string('outcome', 16);

            $table->decimal('model_probability', 6, 5);
            $table->decimal('full_model_probability', 6, 5)->nullable();
            $table->json('full_model_signals')->nullable();
            $table->string('model_mode', 16);
            $table->decimal('lambda_home', 6, 3);
            $table->decimal('lambda_away', 6, 3);
            $table->decimal('rho', 7, 5)->nullable();

            $table->decimal('odds', 7, 3);
            $table->string('bookmaker', 64);
            $table->decimal('fair_probability', 6, 5);
            $table->timestamp('odds_taken_at')->nullable();
            $table->timestamp('computed_at')->nullable();

            // Clôture : nulles jusqu'à log:settle, écrites une seule fois
            $table->unsignedTinyInteger('score_home')->nullable();
            $table->unsignedTinyInteger('score_away')->nullable();
            $table->boolean('outcome_occurred')->nullable();
            $table->string('closing_bookmaker', 40)->nullable();
            $table->decimal('closing_odds', 7, 3)->nullable();
            $table->decimal('closing_fair_probability', 6, 5)->nullable();
            $table->timestamp('closing_quoted_at')->nullable();
            $table->foreignId('closing_odds_movement_id')->nullable()->constrained('odds_movements')->restrictOnDelete();
            $table->decimal('closing_edge', 8, 5)->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->index(['match_id', 'market', 'outcome', 'id']);
            $table->index(['trigger', 'match_id', 'computed_at']);
            $table->index('kickoff_at');
            $table->index('outcome_occurred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_log');
    }
};
