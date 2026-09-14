<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bookmaker d'un snapshot de cotes (clé The Odds API, pinnacle pour le CLV).
     * Les 151 snapshots d'avril 2026 restent à null : bookmaker inconnu, jamais
     * utilisés pour une clôture.
     */
    public function up(): void
    {
        Schema::table('odds_movements', function (Blueprint $table) {
            $table->string('bookmaker', 40)->nullable()->after('match_id');
        });
    }

    public function down(): void
    {
        Schema::table('odds_movements', function (Blueprint $table) {
            $table->dropColumn('bookmaker');
        });
    }
};
