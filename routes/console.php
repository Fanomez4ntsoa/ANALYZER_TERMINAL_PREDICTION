<?php

use App\Jobs\FetchMatchDataJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// PIPELINE AUTOMATIQUE — Scheduler (DÉSACTIVÉ)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Sera réactivé quand le système sera complet (Phase 6+).
// Pour l'instant, utiliser les commandes manuelles ci-dessous.

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// COMMANDE MANUELLE — Lancer le pipeline à la demande
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Artisan::command('pipeline:run {date?} {--all : Ignorer le filtre horaire}', function (?string $date = null) {
    $date = $date ?? now()->format('Y-m-d');
    $all = $this->option('all');
    $this->info("Lancement du pipeline pour le {$date}" . ($all ? ' (tous les matchs)' : '') . "...");

    FetchMatchDataJob::dispatch($date, null, false, $all);

    $this->info("FetchMatchDataJob dispatche.");
    $this->line("Utilisez `php artisan queue:work` pour traiter les jobs.");
})->purpose('Lancer le pipeline de recuperation de donnees manuellement');

Artisan::command('pipeline:run-sync {date?} {--all : Ignorer le filtre horaire}', function (?string $date = null) {
    $date = $date ?? now()->format('Y-m-d');
    $all = $this->option('all');
    $this->info("Lancement du pipeline SYNCHRONE pour le {$date}" . ($all ? ' (tous les matchs)' : '') . "...");

    FetchMatchDataJob::dispatchSync($date, null, true, $all);

    $this->info("Pipeline terminé. Vérifiez la base de données.");
})->purpose('Lancer le pipeline en mode synchrone (sans queue)');

// `combos:generate` retiré du flux (ComboSelectorService hors flux depuis la simplification).
