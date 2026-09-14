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

        // Constructeur de requêtes plutôt que UPDATE … JOIN (syntaxe MySQL) : même
        // résultat sur MariaDB et SQLite. La sous-requête EXISTS reproduit la
        // jointure gauche : une ligne advanced_data réécrite après le coup d'envoi
        // suffit, une absence de ligne ne marque rien. Vérifié le 14/09/2026 sur
        // MariaDB : mêmes 913 identifiants que l'ancienne requête.
        DB::table('matches')
            ->where(function ($query) {
                $query->whereColumn('matches.created_at', '>=', 'matches.match_date')
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('advanced_data')
                            ->whereColumn('advanced_data.match_id', 'matches.id')
                            ->whereColumn('advanced_data.updated_at', '>=', 'matches.match_date');
                    });
            })
            ->update(['post_kickoff_data' => true]);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['post_kickoff_data']);
            $table->dropColumn('post_kickoff_data');
        });
    }
};
