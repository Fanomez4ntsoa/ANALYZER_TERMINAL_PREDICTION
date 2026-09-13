<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Heure du relevé des cotes API-Football (enrichWithApiFootballOdds).
     * Sert de frontière : null = cotes héritées (maximum multi-bookmakers,
     * antérieures au commit c6f76e2 du 2026-09-13), étiquetées `legacy_max`
     * dans predictions.bookmaker.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('odds_fetched_at')->nullable()->after('odds_api_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('odds_fetched_at');
        });
    }
};
