<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rattrapage des scores et lignes du journal non clôturables (26/09/2026).
     *
     * - matches.api_status : dernier statut API-Football connu (FT, PST, CANC…).
     *   Nul pour les matchs importés avant cette migration : la clôture ne s'en sert
     *   que pour annuler, jamais pour trancher une issue.
     * - prediction_log.void_reason : ligne définitivement non clôturable (match
     *   reprogrammé, annulé, arrêté, sur tapis vert, ou score toujours absent au-delà
     *   de la fenêtre de rattrapage). Écrite une seule fois avec settled_at ;
     *   outcome_occurred reste nul. Ni mesurée, ni en attente : comptée à part.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('api_status', 8)->nullable()->after('completed');
        });

        Schema::table('prediction_log', function (Blueprint $table) {
            $table->string('void_reason', 24)->nullable()->after('closing_edge');
        });
    }

    public function down(): void
    {
        Schema::table('prediction_log', function (Blueprint $table) {
            $table->dropColumn('void_reason');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('api_status');
        });
    }
};
