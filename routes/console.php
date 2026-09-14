<?php

use App\Jobs\FetchMatchDataJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// PIPELINE AUTOMATIQUE — Scheduler
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Nécessite `* * * * * php artisan schedule:run` dans la crontab (ou
// `php artisan schedule:work`). Journal : storage/logs/pipeline-*.log.

// Une fois par jour : import, contexte, probabilités, snapshot de cotes.
Schedule::command('pipeline:daily')
    ->dailyAt(config('pipeline.schedule_time'))
    ->timezone(config('pipeline.schedule_timezone'))
    ->withoutOverlapping();

// Clôture automatique : snapshot sans cache juste avant le coup d'envoi, puis
// report du snapshot en cote de clôture une fois le match commencé.
Schedule::command('market:track closing')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('market:track close')->everyFiveMinutes()->withoutOverlapping();

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

    $summary = FetchMatchDataJob::$lastSummary ?? [];
    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Cotes ou scores manquants pour cause d'échec ou de budget : code de sortie non nul
    if (!($summary['indispensable_complete'] ?? false)) {
        $this->error("Pipeline INCOMPLET : cotes ou scores manquants (voir storage/logs/pipeline-*.log).");
        return 1;
    }

    $this->info("Pipeline terminé, cotes complètes.");
    return 0;
})->purpose('Lancer le pipeline en mode synchrone (sans queue)');