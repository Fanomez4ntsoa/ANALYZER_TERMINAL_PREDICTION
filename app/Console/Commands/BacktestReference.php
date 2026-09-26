<?php

namespace App\Console\Commands;

use App\Models\BacktestFdRun;
use App\Services\Backtesting\FootballData\ReferenceCalibration;
use Illuminate\Console\Command;

/**
 * Backtest de référence du panneau de calibration, relancé sur une nouvelle base
 * (déploiement, reconstruction), puis désigné dans .env (BACKTEST_REFERENCE_RUN).
 * Sans cette mise à jour, le panneau pointerait sur un run absent.
 *
 * Configuration unique, celle de la production : échantillon de travail, entrée
 * Bet365 ouverture, Top 5, modèle par défaut (Dixon-Coles, ρ unique). Mesuré le
 * 15/09/2026 : 8 minutes sur le portable.
 *
 * Relançable : si le run désigné est terminé et conforme, rien n'est recalculé
 * sans --force.
 */
class BacktestReference extends Command
{
    protected $signature = 'backtest:reference
                            {--force : Recalculer même si le run désigné est conforme}';

    protected $description = 'Relancer le backtest de référence et le désigner dans .env (BACKTEST_REFERENCE_RUN)';

    public const DIVISIONS = ['E0', 'D1', 'I1', 'SP1', 'F1'];

    // Run #1 du portable, 15/09/2026 : 1X2 Top 5 2223-2324. Contrôle de reproductibilité.
    private const EXPECTED_BRIER_MODEL = 0.19173;
    private const EXPECTED_BRIER_PINNACLE = 0.19103;

    public function handle(ReferenceCalibration $calibration): int
    {
        $current = BacktestFdRun::find(config('football-data.reference_run.id'));
        if (!$this->option('force') && $current !== null && $this->conforms($current, $calibration)) {
            $this->info("Run de référence #{$current->id} déjà terminé et conforme : rien à recalculer (--force pour le relancer).");

            return $this->report($calibration) ? self::SUCCESS : self::FAILURE;
        }

        $started = microtime(true);
        $before = (int) BacktestFdRun::max('id');
        $exit = $this->call('backtest:run', [
            '--sample' => 'work',
            '--input' => 'b365',
            '--divisions' => implode(',', self::DIVISIONS),
            '--label' => 'référence ' . now('UTC')->format('Y-m-d'),
        ]);

        $run = BacktestFdRun::where('id', '>', $before)->orderByDesc('id')->first();
        if ($exit !== self::SUCCESS || $run === null || $run->status !== 'completed') {
            $this->error('Backtest de référence en échec : BACKTEST_REFERENCE_RUN inchangé (' . config('football-data.reference_run.id') . ').');

            return self::FAILURE;
        }

        $minutes = round((microtime(true) - $started) / 60, 1);
        self::writeEnv(app()->environmentFilePath(), 'BACKTEST_REFERENCE_RUN', (string) $run->id);
        config(['football-data.reference_run.id' => $run->id]);
        $this->info("Run #{$run->id} désigné comme référence dans .env (BACKTEST_REFERENCE_RUN={$run->id}), durée {$minutes} min.");

        return $this->report($calibration) ? self::SUCCESS : self::FAILURE;
    }

    private function conforms(BacktestFdRun $run, ReferenceCalibration $calibration): bool
    {
        return $run->status === 'completed'
            && $run->sample === 'work'
            && $run->input_bookmaker === 'b365'
            && $run->divisions === self::DIVISIONS
            && $calibration->configMismatches($run) === [];
    }

    /** Brier 1X2 du panneau, comparé au run du portable. */
    private function report(ReferenceCalibration $calibration): bool
    {
        $summary = $calibration->summary();
        if ($summary === null) {
            $this->error('Panneau de calibration vide : run de référence introuvable ou non terminé.');

            return false;
        }

        $winner = collect($summary['markets'])->firstWhere('market', 'winner');
        $this->line("Panneau : run #{$summary['run_id']}, {$summary['population']}, saisons " . implode('-', $summary['seasons']));
        $this->line(sprintf('1X2 : Brier modèle %.5f · Pinnacle clôture %.5f (portable, run #1 du 15/09/2026 : %.5f · %.5f)',
            $winner['brier_model'] ?? NAN, $winner['brier_pinnacle_close'] ?? NAN, self::EXPECTED_BRIER_MODEL, self::EXPECTED_BRIER_PINNACLE));

        $reproduced = abs(($winner['brier_model'] ?? 0) - self::EXPECTED_BRIER_MODEL) < 0.00001
            && abs(($winner['brier_pinnacle_close'] ?? 0) - self::EXPECTED_BRIER_PINNACLE) < 0.00001;
        if (!$reproduced) {
            $this->warn('Écart avec le portable : vérifier historical_matches (38 780 lignes attendues) et les zips football-data copiés.');
        }
        foreach ($summary['config_mismatches'] as $mismatch) {
            $this->warn("Configuration : {$mismatch}");
        }

        return $reproduced && $summary['config_mismatches'] === [];
    }

    /**
     * Remplace la ligne KEY=… de .env, ou l'ajoute. Une seule écriture, fichier
     * relu entier : aucune autre ligne touchée.
     */
    public static function writeEnv(string $path, string $key, string $value): void
    {
        $contents = is_file($path) ? file_get_contents($path) : '';
        $line = "{$key}={$value}";
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\n") . ($contents === '' ? '' : "\n") . $line . "\n";

        file_put_contents($path, $contents, LOCK_EX);
    }
}
