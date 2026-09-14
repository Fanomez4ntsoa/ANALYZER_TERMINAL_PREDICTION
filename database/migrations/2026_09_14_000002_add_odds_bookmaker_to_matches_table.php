<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bookmaker unique dont viennent les cotes odds_* d'un match, repris dans
     * predictions.bookmaker. Jusqu'ici l'étiquette venait de la config The Odds API,
     * qui devient le bookmaker du CLV (Pinnacle) : elle aurait étiqueté des cotes
     * Bet365 comme Pinnacle.
     *
     * Rattrapage : toute cote relevée depuis c6f76e2 (odds_fetched_at renseigné) vient
     * d'API-Football, bookmaker 8, jamais configuré autrement : bet365.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('odds_bookmaker', 40)->nullable()->after('odds_fetched_at');
        });

        DB::table('matches')->whereNotNull('odds_fetched_at')->update(['odds_bookmaker' => 'bet365']);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('odds_bookmaker');
        });
    }
};
