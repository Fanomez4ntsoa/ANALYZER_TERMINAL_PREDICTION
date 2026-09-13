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

Artisan::command('combos:generate {date?}', function (?string $date = null) {
    $date = $date ?? now()->format('Y-m-d');
    $this->info("Generation des combos pour le {$date}...");

    $selector = app(\App\Services\Betting\ComboSelectorService::class);
    $result = $selector->generateForDate($date);

    $stats = $result['stats'];
    $aiCombo = $result['ai_combo'] ?? null;

    $this->table(['Metrique', 'Valeur'], [
        ['Matchs eligibles', $stats['eligible_matches']],
        ['Candidats picks (multi-marches)', $stats['candidate_picks']],
        ['Combinaisons generees', $stats['combinations']],
        ['Combos valides (apres filtre)', $stats['valid']],
        ['Combos algo sauvegardes', $stats['saved'] ?? count($result['combos'])],
        ['IA recommendation', $stats['ai_recommendation'] ?? '-'],
        ['IA confidence', $stats['ai_confidence'] !== null ? $stats['ai_confidence'] . '%' : '-'],
        ['IA tokens', $stats['ai_tokens'] ? json_encode($stats['ai_tokens']) : '-'],
        ['IA error', $stats['ai_error'] ?? '-'],
        ['Combo IA en DB (rank=0)', $aiCombo ? 'OUI' : 'NON'],
    ]);

    // Combo IA (rank=0) — affichage prioritaire si présent
    if ($aiCombo) {
        $this->newLine();
        $this->info("COMBO IA (rank 0) — Cote: {$aiCombo->total_odds} | Confiance: {$aiCombo->combo_score}% | Picks: {$aiCombo->match_count}");
        foreach ($aiCombo->picks as $p) {
            $market = $p['market'] ?? '?';
            $line = "  {$p['match']} → {$market} @{$p['odds']}";
            if (isset($p['safety_score'])) {
                $line .= " [safety {$p['safety_score']}/100]";
            }
            $this->line($line);
            if (!empty($p['reasoning'])) {
                $this->line("    {$p['reasoning']}");
            }
        }
    }

    // Combos algorithmiques (rank 1-3)
    foreach ($result['combos'] as $combo) {
        $this->newLine();
        $this->info("COMBO #{$combo->rank} — Cote: {$combo->total_odds} | Score: {$combo->combo_score} | Confiance moy: {$combo->avg_confidence}%");
        foreach ($combo->picks as $p) {
            $home = $p['home'] ?? '?';
            $away = $p['away'] ?? '?';
            $comp = $p['competition'] ?? '?';
            $market = $p['market'] ?? '?';
            $pick = $p['pick'] ?? '?';
            $this->line("  {$home} v {$away} ({$comp}): {$market}={$pick} @{$p['odds']} ({$p['confidence']}%)");
        }
    }

    if (empty($result['combos']) && !$aiCombo) {
        $this->warn('Aucun combo viable. Verifiez les seuils (.env), le nombre de matchs analyses, ou les decisions IA.');
    }
})->purpose('Generer les top 3 combos algo + combo IA (rank=0)');
