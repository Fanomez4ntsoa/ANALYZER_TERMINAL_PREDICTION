<?php

namespace App\Console\Commands;

use App\Services\Backtesting\FootballData\CalibrationBacktestService;
use Illuminate\Console\Command;

class BacktestRun extends Command
{
    protected $signature = 'backtest:run
                            {--sample=work : work (2122-2324) | holdout (2425-2526, réservé) | custom (avec --seasons)}
                            {--seasons= : Saisons explicites (AABB, virgules). Les saisons réservées exigent --sample=holdout}
                            {--divisions= : Divisions (ex. E0,D1,I1,SP1,F1) — défaut : toutes}
                            {--input=b365 : Bookmaker dont les cotes d\'OUVERTURE alimentent le modèle : b365 | ps}
                            {--label= : Étiquette du run}
                            {--legacy-home : Mesure : rétablir l\'ancien facteur domicile appliqué après fusion (y compris au signal marché)}
                            {--legacy-share : Mesure : rétablir l\'ancien recalage du total à partage domicile/extérieur constant}
                            {--anchor : Mesure : activer l\'ancrage du total sans O/U sur la moyenne du championnat (saisons antérieures au match)}';

    protected $description = 'Backtest de calibration du modèle de production (marché seul) sur football-data — aucune mise, aucun ROI';

    public function handle(CalibrationBacktestService $service): int
    {
        $sample = $this->option('sample');
        $work = config('football-data.work_seasons');
        $holdout = config('football-data.holdout_seasons');

        $seasons = $this->option('seasons')
            ? array_map('trim', explode(',', $this->option('seasons')))
            : match ($sample) {
                'work' => $work,
                'holdout' => $holdout,
                default => null,
            };

        if (empty($seasons)) {
            $this->error('Aucune saison : utilisez --sample=work|holdout ou --seasons=AABB,...');
            return self::FAILURE;
        }

        $touchesHoldout = array_intersect($seasons, $holdout);
        if (!empty($touchesHoldout) && $sample !== 'holdout') {
            $this->error('Saisons réservées demandées (' . implode(',', $touchesHoldout) . ') sans --sample=holdout. Refusé.');
            return self::FAILURE;
        }
        if ($sample === 'holdout') {
            $this->warn('ÉCHANTILLON RÉSERVÉ : aucun réglage ne doit découler de ce run.');
        }

        $input = $this->option('input');
        if (!in_array($input, ['b365', 'ps'], true)) {
            $this->error('--input doit valoir b365 ou ps');
            return self::FAILURE;
        }

        $divisions = $this->option('divisions')
            ? array_map(fn ($d) => strtoupper(trim($d)), explode(',', $this->option('divisions')))
            : null;

        // Modèle de production par défaut. Les options ne servent qu'à mesurer un
        // changement à la fois.
        $legacyHome = (bool) $this->option('legacy-home');
        $legacyShare = (bool) $this->option('legacy-share');
        $anchor = (bool) $this->option('anchor');
        config([
            'xg-model.legacy_home_advantage_after_fusion' => $legacyHome,
            'xg-model.legacy_constant_share_rescaling' => $legacyShare,
            'xg-model.anchor_total_on_league_average' => $anchor,
        ]);

        $config = [
            'sample' => $sample,
            'seasons' => array_values($seasons),
            'divisions' => $divisions,
            'input_bookmaker' => $input,
            'label' => $this->option('label'),
            'model' => [
                'legacy_home_advantage_after_fusion' => $legacyHome,
                'legacy_constant_share_rescaling' => $legacyShare,
                'anchor_total_on_league_average' => $anchor,
                'estimators_scoped_to_prior_seasons' => true,
            ],
        ];

        $this->info('Backtest calibration — saisons ' . implode(',', $seasons) . ' — divisions ' . ($divisions ? implode(',', $divisions) : 'toutes') . " — entrée {$input} (ouverture)");
        $this->line('Modèle : facteur domicile ' . ($legacyHome ? 'ANCIEN (après fusion, marché inclus)' : 'sur les seuls signaux hors marché')
            . ' — recalage O/U ' . ($legacyShare ? 'ANCIEN (partage constant)' : 'conjoint (partage cherché au total O/U)')
            . ' — total sans O/U ' . ($anchor ? 'ancré (saisons antérieures au match)' : 'libre (grille)'));

        $bar = $this->output->createProgressBar();
        $bar->start();
        $run = $service->run($config, fn (int $n) => $bar->setProgress($n));
        $bar->finish();
        $this->newLine(2);

        $this->info("Run #{$run->id} terminé — {$run->matches_evaluated}/{$run->matches_loaded} matchs évalués — export {$run->export_path}");
        $this->table(['Exclusion', 'Effectif'], collect($run->exclusions)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $rows = [];
        foreach ($run->results as $family => $markets) {
            foreach ($markets as $market => $r) {
                $o = $r['overall'];
                $rows[] = [$family, $market, $o['n'], $o['brier_model'], $o['brier_pinnacle_close'] ?? '-', $o['mse_model_vs_pinnacle_close'] ?? '-'];
            }
        }
        $this->table(['Famille', 'Marché', 'Lignes', 'Brier modèle', 'Brier Pinnacle clôture', 'MSE vs clôture'], $rows);

        foreach ($run->warnings as $w) {
            $this->line("  · {$w}");
        }

        return self::SUCCESS;
    }
}
