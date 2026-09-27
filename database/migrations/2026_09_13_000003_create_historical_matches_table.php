<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matchs historiques football-data.co.uk, distincts de `matches`.
     * Cotes d'un bookmaker nommé uniquement (Bet365, Pinnacle) : aucune colonne
     * Max/Avg multi-bookmakers n'est importée.
     */
    public function up(): void
    {
        Schema::create('historical_matches', function (Blueprint $table) {
            $table->id();
            $table->char('season', 4);          // ex. 2324
            $table->string('div', 4);           // ex. E0
            $table->unsignedInteger('league_id')->nullable(); // id API-Football (non bloquant)
            $table->date('match_date');
            $table->string('kickoff_time', 5)->nullable();
            $table->string('home_team');
            $table->string('away_team');
            $table->unsignedTinyInteger('fthg')->nullable();
            $table->unsignedTinyInteger('ftag')->nullable();
            $table->char('ftr', 1)->nullable();

            foreach (['b365_open', 'b365_close', 'ps_open', 'ps_close'] as $prefix) {
                $table->decimal("{$prefix}_home", 7, 3)->nullable();
                $table->decimal("{$prefix}_draw", 7, 3)->nullable();
                $table->decimal("{$prefix}_away", 7, 3)->nullable();
                $table->decimal("{$prefix}_over25", 7, 3)->nullable();
                $table->decimal("{$prefix}_under25", 7, 3)->nullable();
            }

            $table->string('source_file', 64);
            // Défaut explicite : indépendant d'explicit_defaults_for_timestamp (2026_09_27_000001)
            $table->timestamp('imported_at')->useCurrent();
            $table->timestamps();

            $table->unique(['season', 'div', 'match_date', 'home_team', 'away_team'], 'historical_matches_unique_fixture');
            $table->index(['season', 'div']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_matches');
    }
};
