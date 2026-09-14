<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matchs contaminés : une donnée du match a été écrite après son coup d'envoi.
     * Exclus définitivement de toute mesure (règle 5 de CLAUDE.md).
     *
     * Marquage initial, décidé le 14/09/2026 : match créé après son coup d'envoi
     * (backfill du 08/04/2026, rattrapage J-1) OU advanced_data réécrites après
     * le coup d'envoi (relances du pipeline les 26/04 et 01/05/2026).
     * Comptage avant migration : 913 matchs sur 1 140 (816 créés après, dont 794
     * du backfill, plus 97 aux données avancées réécrites).
     *
     * Ensuite, le flag est posé à la création d'un match dont le coup d'envoi est
     * passé (MatchEnricherService::upsertFromApiFootball). Il n'est jamais retiré.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('post_kickoff_data')->default(false)->after('completed');
            $table->index('post_kickoff_data');
        });

        DB::statement(<<<'SQL'
            UPDATE matches m
            LEFT JOIN advanced_data a ON a.match_id = m.id
            SET m.post_kickoff_data = 1
            WHERE m.created_at >= m.match_date
               OR a.updated_at >= m.match_date
        SQL);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['post_kickoff_data']);
            $table->dropColumn('post_kickoff_data');
        });
    }
};
