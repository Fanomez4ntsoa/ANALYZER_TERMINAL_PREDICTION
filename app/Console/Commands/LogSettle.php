<?php

namespace App\Console\Commands;

use App\Services\PredictionLog\PredictionLogSettler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Clôture du journal des sélections pour les matchs d'une date (la veille par
 * défaut). Lancée par pipeline:daily juste après pipeline:run-sync, qui met à jour
 * les scores de la veille.
 *
 * Code de sortie non nul si une ligne d'un match terminé n'a pas pu être clôturée
 * (score absent, match changé, exception). Un match non terminé ou une clôture
 * Pinnacle absente ne sont que signalés.
 */
class LogSettle extends Command
{
    protected $signature = 'log:settle
                            {--date= : Date des matchs (YYYY-MM-DD, défaut : la veille)}';

    protected $description = 'Clôturer le journal des sélections : issue réalisée, clôture Pinnacle, closing_edge';

    public function handle(PredictionLogSettler $settler): int
    {
        $date = $this->option('date') ?? now()->subDay()->format('Y-m-d');
        $report = $settler->settle($date);

        $this->info("Journal du {$date} : {$report['settled']} ligne(s) clôturée(s) sur {$report['entries']} en attente");

        if ($report['pending_matches']) {
            $this->warn('Lignes laissées en attente :');
            $this->table(['Match', 'Lignes', 'Raison', 'Détail'], array_map(fn (array $p) => [
                "#{$p['match_id']} {$p['match']}", $p['lines'], $p['reason'], $p['detail'] ?? '',
            ], $report['pending_matches']));
        }

        if ($report['closing_edge_missing']) {
            $this->line('closing_edge nul (sans clôture Pinnacle fiable ou marché non coté) : ' . json_encode($report['closing_edge_missing']));
        }

        if ($report['older_pending']) {
            $this->warn('Lignes en attente des jours précédents : ' . json_encode($report['older_pending']) . ' (relancer log:settle --date=)');
        }

        $level = $report['failures'] > 0 ? 'error' : (($report['pending'] || $report['older_pending']) ? 'warning' : 'info');
        Log::channel('pipeline')->log($level, "log:settle : {$report['settled']} ligne(s) clôturée(s) pour le {$date}", $report);

        if ($report['failures'] > 0) {
            $this->error("{$report['failures']} ligne(s) d'un match terminé non clôturée(s)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
