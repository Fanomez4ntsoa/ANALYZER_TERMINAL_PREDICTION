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
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->json('config');              // Ligues, période, marchés, seuils
            $table->date('date_from');
            $table->date('date_to');
            $table->string('staking_strategy');  // flat, kelly, proportional
            $table->decimal('bankroll', 10, 2)->default(1000);
            $table->decimal('unit_stake', 8, 2)->default(10);

            // Métriques agrégées
            $table->unsignedInteger('total_matches')->default(0);
            $table->unsignedInteger('total_predictions')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->decimal('win_rate', 5, 2)->default(0);
            $table->decimal('roi', 8, 2)->default(0);
            $table->decimal('yield_pct', 8, 2)->default(0);
            $table->decimal('max_drawdown', 8, 2)->default(0);
            $table->decimal('brier_score', 6, 4)->default(0);
            $table->decimal('final_bankroll', 10, 2)->default(1000);

            // Segmentation (JSON)
            $table->json('by_league')->nullable();
            $table->json('by_market')->nullable();
            $table->json('by_confidence')->nullable();
            $table->json('by_consensus')->nullable();
            $table->json('by_odds_range')->nullable();
            $table->json('calibration')->nullable();
            $table->json('bankroll_curve')->nullable();

            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->timestamps();
        });

        Schema::create('backtest_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('backtest_runs')->cascadeOnDelete();

            // Match info
            $table->unsignedBigInteger('api_fixture_id');
            $table->string('home_team');
            $table->string('away_team');
            $table->string('league');
            $table->unsignedInteger('league_id');
            $table->dateTime('match_date');
            $table->unsignedTinyInteger('score_home');
            $table->unsignedTinyInteger('score_away');

            // Prédiction
            $table->string('market');           // winner, overUnder, btts, doubleChance
            $table->string('pick');             // 1, X, 2, Over, Under, Yes, No, 1X, X2
            $table->unsignedTinyInteger('confidence');
            $table->decimal('odds', 6, 3)->nullable();
            $table->decimal('probability', 5, 2);  // Probabilité modèle (0-100)

            // Résultat
            $table->boolean('won');
            $table->decimal('profit', 8, 2)->default(0);  // +gain ou -mise
            $table->decimal('bankroll_after', 10, 2)->default(0);

            $table->timestamps();

            $table->index(['run_id', 'market']);
            $table->index(['run_id', 'league_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_predictions');
        Schema::dropIfExists('backtest_runs');
    }
};
