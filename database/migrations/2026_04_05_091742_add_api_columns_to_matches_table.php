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
        Schema::table('matches', function (Blueprint $table) {
            // IDs externes pour lier aux APIs
            $table->unsignedBigInteger('api_football_id')->nullable()->unique()->after('id');
            $table->string('odds_api_event_id')->nullable()->after('api_football_id');

            // IDs des équipes API-Football (pour H2H, stats, etc.)
            $table->unsignedInteger('home_team_id')->nullable()->after('home_team');
            $table->unsignedInteger('away_team_id')->nullable()->after('away_team');

            // League ID API-Football
            $table->unsignedInteger('league_id')->nullable()->after('competition');
            $table->unsignedSmallInteger('season')->nullable()->after('league_id');

            // Source des données (manual = saisie manuelle, api = pipeline auto)
            $table->enum('data_source', ['manual', 'api'])->default('manual')->after('season');

            // Timestamp du dernier enrichissement pipeline
            $table->timestamp('enriched_at')->nullable()->after('validated_at');

            // Index pour les requêtes fréquentes du pipeline
            $table->index(['match_date', 'data_source']);
            $table->index('league_id');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['match_date', 'data_source']);
            $table->dropIndex(['league_id']);
            $table->dropColumn([
                'api_football_id',
                'odds_api_event_id',
                'home_team_id',
                'away_team_id',
                'league_id',
                'season',
                'data_source',
                'enriched_at',
            ]);
        });
    }
};
