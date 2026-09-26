<?php

namespace App\Console\Commands;

use App\Services\PredictionLog\PredictionLogSettler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Clôture du journal des sélections : toutes les dates qui ont encore des lignes
 * en attente, jusqu'à --date comprise (la veille par défaut). Lancée par
 * pipeline:daily juste après pipeline:run-sync, qui met à jour les scores de la
 * veille et rattrape les plus anciens.
 *
 * Code de sortie non nul si une ligne d'un match terminé n'a pas pu être clôturée
 * (score absent, équipes changées, exception). Un match non terminé, une ligne
 * déclarée non clôturable ou une clôture Pinnacle absente ne sont que signalés.
 */
class LogSettle extends Command
{
    protected $signature = 'log:settle
                            {--date= : Dernière date clôturée (YYYY-MM-DD, défaut : la veille) ; les dates antérieures en attente aussi}';

    protected $description = 'Clôturer le journal des sélections : issue réalisée, clôture Pinnacle, closing_edge';

    public function handle(PredictionLogSettler $settler): int
    {
        $date = $this->option('date') ?? now()->subDay()->format('Y-m-d');
        $report = $settler->settleUpTo($date);
        $voided = array_sum($report['voided']);

        $this->info("Journal jusqu'au {$date} (" . implode(', ', $report['dates']) . ") : {$report['settled']} ligne(s) clôturée(s), "
            . "{$voided} déclarée(s) non clôturable(s), sur {$report['entries']} en attente");

        if ($report['voided_matches']) {
            $this->warn('Lignes déclarées non clôturables :');
            $this->table(['Match', 'Lignes', 'Raison', 'Détail'], array_map(fn (array $v) => [
                "#{$v['match_id']} {$v['match']}", $v['lines'], $v['reason'], $v['detail'],
            ], $report['voided_matches']));
        }

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
            $this->warn('Lignes encore en attente des jours précédents : ' . json_encode($report['older_pending']) . ' (score à rattraper par le pipeline)');
        }

        $level = $report['failures'] > 0 ? 'error' : (($report['pending'] || $report['older_pending'] || $report['voided']) ? 'warning' : 'info');
        Log::channel('pipeline')->log($level, "log:settle : {$report['settled']} ligne(s) clôturée(s), {$voided} non clôturable(s) jusqu'au {$date}", $report);

        if ($report['failures'] > 0) {
            $this->error("{$report['failures']} ligne(s) d'un match terminé non clôturée(s)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
