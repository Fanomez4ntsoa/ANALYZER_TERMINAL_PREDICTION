<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backtest de calibration sur football-data (distinct de backtest_runs /
     * backtest_predictions, conservés pour l'ancien moteur).
     * Aucune mise, aucun ROI, aucune bankroll.
     */
    public function up(): void
    {
        Schema::create('backtest_fd_runs', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60)->nullable();
            $table->string('sample', 16);                  // work | holdout | custom
            $table->string('input_bookmaker', 8);           // b365 | ps (cotes d'OUVERTURE en entrée du modèle)
            $table->json('seasons');
            $table->json('divisions')->nullable();
            $table->json('config');
            $table->unsignedInteger('matches_loaded')->default(0);
            $table->unsignedInteger('matches_evaluated')->default(0);
            $table->json('matches_by_season')->nullable();
            $table->json('exclusions')->nullable();         // cause => effectif
            $table->json('results')->nullable();            // famille → marché → métriques
            $table->json('warnings')->nullable();
            $table->string('export_path')->nullable();
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('backtest_fd_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('backtest_fd_runs')->cascadeOnDelete();
            $table->foreignId('historical_match_id')->constrained('historical_matches')->cascadeOnDelete();
            $table->char('season', 4);
            $table->string('div', 4);
            $table->string('family', 16);                   // adjustment | derived | transfer
            $table->string('market', 24);                   // winner, overUnder25, doubleChance, btts, overUnder15, overUnder35
            $table->string('outcome', 8);
            $table->decimal('model_probability', 6, 5);
            $table->decimal('input_open_fair', 6, 5)->nullable();     // bookmaker d'entrée, ouverture, marge retirée
            $table->decimal('pinnacle_close_fair', 6, 5)->nullable(); // référence : Pinnacle clôture, marge retirée
            $table->boolean('observed');

            $table->index(['run_id', 'family', 'market']);
            $table->index(['run_id', 'div']);
            $table->index(['run_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_fd_predictions');
        Schema::dropIfExists('backtest_fd_runs');
    }
};
