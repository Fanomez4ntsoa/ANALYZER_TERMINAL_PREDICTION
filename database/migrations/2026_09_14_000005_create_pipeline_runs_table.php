<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un passage de pipeline:daily. Source de l'indicateur de fraîcheur de
     * l'interface : dernier passage réussi, matchs sans cotes, hors périmètre.
     *
     * status : running (en cours ou interrompu brutalement), success (toutes les
     * étapes à 0), incomplete (une étape en échec, les suivantes ont tourné),
     * failed (import en exception, étapes suivantes non lancées).
     */
    public function up(): void
    {
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->date('run_date');
            $table->enum('status', ['running', 'success', 'incomplete', 'failed'])->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('steps')->nullable();      // commande → code de sortie, durée, exception
            $table->json('fetch_summary')->nullable(); // bilan FetchMatchDataJob (cotes, hors périmètre…)
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
