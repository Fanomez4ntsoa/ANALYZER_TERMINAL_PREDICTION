<?php

namespace App\Services\Backtesting\FootballData;

use App\Models\BacktestFdPrediction;
use App\Models\BacktestFdRun;
use App\Models\FootballMatch;
use App\Models\HistoricalMatch;
use App\Services\Backtesting\SeasonScopedEstimator;
use App\Services\Probability\PoissonModelService;
use App\Services\Probability\XGModelService;
use Illuminate\Support\Facades\Storage;

/**
 * Backtest de CALIBRATION du modèle de production (XGModelService, mode marché seul)
 * sur les matchs historiques football-data.
 *
 * Trois familles :
 *  - adjustment : 1X2 et O/U 2.5 — les marchés qui servent à ajuster les λ.
 *                 Contrôle de cohérence uniquement (le modèle y recopie ~ le bookmaker).
 *  - derived    : BTTS, O/U 1.5, O/U 3.5 (tests INDÉPENDANTS : forme de la loi jointe),
 *                 double chance (somme du 1X2 : n'apporte rien de plus que le contrôle 1X2).
 *  - transfer   : λ ajustés sur le seul 1X2, puis O/U 2.5 prédit et comparé au marché réel.
 *
 * Entrée du modèle : cotes d'OUVERTURE du bookmaker d'entrée. Les cotes de clôture ne sont
 * jamais fournies au modèle ; la clôture Pinnacle démarginalisée sert de référence.
 * Aucune mise, aucun ROI, aucune cote inventée : ligne exclue si la cote réelle manque.
 *
 * Règle générale (SeasonScopedEstimator) : avant chaque match, tout estimateur de
 * paramètre sur données historiques est borné aux saisons strictement antérieures
 * à celle du match ; tout accès non borné pendant le run lève une exception.
 */
class CalibrationBacktestService
{
    public const FAMILY_ADJUSTMENT = 'adjustment';
    public const FAMILY_DERIVED = 'derived';
    public const FAMILY_TRANSFER = 'transfer';

    /** Marchés dérivés réellement indépendants du contrôle 1X2 */
    public const INDEPENDENT_DERIVED_MARKETS = ['btts', 'overUnder15', 'overUnder35'];

    private const BIN_WIDTH = 0.05;

    /** @var SeasonScopedEstimator[] */
    private array $estimators;

    /** @param iterable<SeasonScopedEstimator> $estimators */
    public function __construct(
        private XGModelService $xgModel,
        private PoissonModelService $poisson,
        iterable $estimators = [],
    ) {
        $this->estimators = is_array($estimators) ? $estimators : iterator_to_array($estimators, false);
        foreach ($this->estimators as $e) {
            if (!$e instanceof SeasonScopedEstimator) {
                throw new \InvalidArgumentException(get_class($e) . ' doit implémenter SeasonScopedEstimator');
            }
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RUN COMPLET (base + export)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * @param array{sample:string, seasons:string[], divisions:?string[], input_bookmaker:string, label:?string} $config
     */
    public function run(array $config, ?callable $progress = null): BacktestFdRun
    {
        $run = BacktestFdRun::create([
            'label' => $config['label'] ?? null,
            'sample' => $config['sample'],
            'input_bookmaker' => $config['input_bookmaker'],
            'seasons' => $config['seasons'],
            'divisions' => $config['divisions'],
            'config' => $config,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $query = HistoricalMatch::whereIn('season', $config['seasons']);
            if (!empty($config['divisions'])) {
                $query->whereIn('div', $config['divisions']);
            }
            $matches = $query->orderBy('match_date')->orderBy('id')->get();

            $evaluation = $this->evaluate($matches, $config['input_bookmaker'], function (array $rows) use ($run) {
                $this->storeRows($run, $rows);
            }, $progress);

            $warnings = $evaluation['warnings'];
            if ($config['sample'] === 'holdout') {
                $warnings[] = 'ÉCHANTILLON RÉSERVÉ (holdout) : aucun réglage ne doit être fait à partir de ces résultats.';
            }

            $exportPath = $this->export($run, $config, $evaluation, $warnings);

            $run->update([
                'matches_loaded' => $matches->count(),
                'matches_evaluated' => $evaluation['matches_evaluated'],
                'matches_by_season' => $evaluation['matches_by_season'],
                'exclusions' => $evaluation['exclusions'],
                'results' => $evaluation['results'],
                'warnings' => $warnings,
                'export_path' => $exportPath,
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'warnings' => [$e->getMessage()], 'finished_at' => now()]);
            throw $e;
        }

        return $run->fresh();
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ÉVALUATION PURE (sans base) — réutilisable sur des matchs en mémoire
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * @param iterable<HistoricalMatch> $matches
     * @param callable|null $sink  Reçoit les lignes de prédiction d'un match (persistance)
     * @return array{matches_evaluated:int, matches_by_season:array, exclusions:array, results:array, warnings:array}
     */
    public function evaluate(iterable $matches, string $inputBookmaker, ?callable $sink = null, ?callable $progress = null): array
    {
        $prefix = $inputBookmaker === 'ps' ? 'ps_open' : 'b365_open';

        $exclusions = [
            'missing_score' => 0,
            'missing_input_1x2' => 0,
            'missing_input_ou25' => 0,
            'missing_pinnacle_close_1x2' => 0,
            'missing_pinnacle_close_ou25' => 0,
        ];
        $bySeason = [];
        $evaluated = 0;
        $aggregator = new CalibrationAggregator(self::BIN_WIDTH);

        foreach ($this->estimators as $estimator) {
            $estimator->requireScope(true);
        }

        try {
            foreach ($matches as $match) {
                foreach ($this->estimators as $estimator) {
                    $estimator->scopeToSeasonsBefore((string) $match->season);
                }

                $bySeason[$match->season] = ($bySeason[$match->season] ?? ['loaded' => 0, 'evaluated' => 0]);
                $bySeason[$match->season]['loaded']++;

                if ($match->fthg === null || $match->ftag === null) {
                    $exclusions['missing_score']++;
                    continue;
                }

                $in1x2 = $this->triplet($match, $prefix);
                if ($in1x2 === null) {
                    $exclusions['missing_input_1x2']++;
                    continue;
                }
                $inOu = $this->pair($match, $prefix);
                if ($inOu === null) {
                    $exclusions['missing_input_ou25']++;
                }

                $refClose1x2 = $this->fairTriplet($this->triplet($match, 'ps_close'));
                $refCloseOu = $this->fairPair($this->pair($match, 'ps_close'));
                if ($refClose1x2 === null) {
                    $exclusions['missing_pinnacle_close_1x2']++;
                }
                if ($refCloseOu === null) {
                    $exclusions['missing_pinnacle_close_ou25']++;
                }

                $inFair1x2 = $this->fairTriplet($in1x2);
                $inFairOu = $this->fairPair($inOu);

                $rows = [];

                // ── Passe complète : 1X2 + O/U 2.5 en entrée → ajustement + dérivés ──
                if ($inOu !== null) {
                    $full = $this->xgModel->predict($this->toFootballMatch($match, $in1x2, $inOu), true);
                    $rows = array_merge(
                        $rows,
                        $this->adjustmentRows($match, $full, $inFair1x2, $inFairOu, $refClose1x2, $refCloseOu),
                        $this->derivedRows($match, $full, $inFair1x2, $refClose1x2),
                    );
                }

                // ── Passe de transfert : 1X2 seul en entrée → O/U 2.5 comparé au marché réel ──
                $transfer = $this->xgModel->predict($this->toFootballMatch($match, $in1x2, null), true);
                $rows = array_merge($rows, $this->transferRows($match, $transfer, $inFairOu, $refCloseOu));

                foreach ($rows as $row) {
                    $aggregator->add($row);
                }
                if ($sink !== null) {
                    $sink($rows);
                }

                $evaluated++;
                $bySeason[$match->season]['evaluated']++;
                if ($progress !== null) {
                    $progress($evaluated);
                }
            }
        } finally {
            foreach ($this->estimators as $estimator) {
                $estimator->scopeToSeasonsBefore(null);
                $estimator->requireScope(false);
            }
        }

        return [
            'matches_evaluated' => $evaluated,
            'matches_by_season' => $bySeason,
            'exclusions' => $exclusions,
            'results' => $aggregator->results(),
            'warnings' => [
                'Les marchés d\'ajustement (1X2, O/U 2.5) sont un contrôle de cohérence : le modèle y recopie ~ le bookmaker d\'entrée.',
                'La double chance est une somme du 1X2 côté modèle ET côté référence : elle n\'apporte rien de plus que le contrôle 1X2.',
                'Tests indépendants du modèle de Poisson : ' . implode(', ', self::INDEPENDENT_DERIVED_MARKETS) . ' (forme de la distribution jointe).',
                'Les marchés dérivés n\'ont pas de cote propre dans football-data : calibration contre le résultat observé uniquement.',
            ],
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // LIGNES PAR FAMILLE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function adjustmentRows(HistoricalMatch $m, array $pred, ?array $inFair1x2, ?array $inFairOu, ?array $ref1x2, ?array $refOu): array
    {
        $a = $pred['analysis'];
        $obs = $this->observed($m);
        $rows = [];

        // Clés '1' et '2' : PHP les convertit en entiers, d'où le cast explicite
        foreach (['1' => 'home', 'X' => 'draw', '2' => 'away'] as $outcome => $key) {
            $outcome = (string) $outcome;
            $rows[] = $this->row($m, self::FAMILY_ADJUSTMENT, 'winner', $outcome, $a['1x2'][$key] / 100, $inFair1x2[$outcome] ?? null, $ref1x2[$outcome] ?? null, $obs['winner'] === $outcome);
        }
        foreach (['Over' => 'over', 'Under' => 'under'] as $outcome => $key) {
            $rows[] = $this->row($m, self::FAMILY_ADJUSTMENT, 'overUnder25', $outcome, $a['overUnder25'][$key] / 100, $inFairOu[$outcome] ?? null, $refOu[$outcome] ?? null, $obs['overUnder25'] === $outcome);
        }

        return $rows;
    }

    private function derivedRows(HistoricalMatch $m, array $pred, ?array $inFair1x2, ?array $ref1x2): array
    {
        $a = $pred['analysis'];
        $obs = $this->observed($m);
        $rows = [];

        // Double chance : référence reconstruite par somme du 1X2 Pinnacle clôture démarginalisé
        $dcRef = $ref1x2 === null ? null : ['1X' => $ref1x2['1'] + $ref1x2['X'], 'X2' => $ref1x2['X'] + $ref1x2['2'], '12' => $ref1x2['1'] + $ref1x2['2']];
        $dcIn = $inFair1x2 === null ? null : ['1X' => $inFair1x2['1'] + $inFair1x2['X'], 'X2' => $inFair1x2['X'] + $inFair1x2['2'], '12' => $inFair1x2['1'] + $inFair1x2['2']];
        foreach (['1X', 'X2', '12'] as $outcome) {
            $rows[] = $this->row($m, self::FAMILY_DERIVED, 'doubleChance', $outcome, $a['doubleChance'][$outcome] / 100, $dcIn[$outcome] ?? null, $dcRef[$outcome] ?? null, in_array($obs['winner'], str_split($outcome), true));
        }

        // BTTS
        foreach (['Yes' => 'yes', 'No' => 'no'] as $outcome => $key) {
            $rows[] = $this->row($m, self::FAMILY_DERIVED, 'btts', $outcome, $a['btts'][$key] / 100, null, null, $obs['btts'] === $outcome);
        }

        // O/U 1.5 et 3.5 depuis les mêmes λ (matrice de Poisson de production)
        $lh = $pred['lambdas']['home'];
        $la = $pred['lambdas']['away'];
        foreach (['overUnder15' => 1.5, 'overUnder35' => 3.5] as $market => $line) {
            $ou = $this->poisson->predictOverUnder($lh, $la, $line);
            $total = $m->fthg + $m->ftag;
            $rows[] = $this->row($m, self::FAMILY_DERIVED, $market, 'Over', $ou['over'] / 100, null, null, $total > $line);
            $rows[] = $this->row($m, self::FAMILY_DERIVED, $market, 'Under', $ou['under'] / 100, null, null, $total < $line);
        }

        return $rows;
    }

    private function transferRows(HistoricalMatch $m, array $pred, ?array $inFairOu, ?array $refOu): array
    {
        $a = $pred['analysis'];
        $obs = $this->observed($m);
        $rows = [];
        foreach (['Over' => 'over', 'Under' => 'under'] as $outcome => $key) {
            $rows[] = $this->row($m, self::FAMILY_TRANSFER, 'overUnder25', $outcome, $a['overUnder25'][$key] / 100, $inFairOu[$outcome] ?? null, $refOu[$outcome] ?? null, $obs['overUnder25'] === $outcome);
        }
        return $rows;
    }

    private function row(HistoricalMatch $m, string $family, string $market, string $outcome, float $p, ?float $inFair, ?float $refFair, bool $observed): array
    {
        return [
            'historical_match_id' => $m->id,
            'season' => $m->season,
            'div' => $m->div,
            'family' => $family,
            'market' => $market,
            'outcome' => $outcome,
            'model_probability' => round($p, 5),
            'input_open_fair' => $inFair === null ? null : round($inFair, 5),
            'pinnacle_close_fair' => $refFair === null ? null : round($refFair, 5),
            'observed' => $observed,
        ];
    }

    private function observed(HistoricalMatch $m): array
    {
        $h = $m->fthg;
        $a = $m->ftag;
        return [
            'winner' => $h > $a ? '1' : ($h === $a ? 'X' : '2'),
            'overUnder25' => ($h + $a) > 2.5 ? 'Over' : 'Under',
            'btts' => ($h > 0 && $a > 0) ? 'Yes' : 'No',
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // COTES → ENTRÉE MODÈLE / PROBABILITÉS FAIR
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /** Objet FootballMatch non persisté : seules les cotes d'ouverture fournies sont visibles du modèle. */
    private function toFootballMatch(HistoricalMatch $m, array $odds1x2, ?array $oddsOu): FootballMatch
    {
        $fm = new FootballMatch([
            'home_team' => $m->home_team,
            'away_team' => $m->away_team,
            'match_date' => $m->match_date,
            'competition' => $m->div,
            'league_id' => $m->league_id,
            'odds_home' => $odds1x2['1'],
            'odds_draw' => $odds1x2['X'],
            'odds_away' => $odds1x2['2'],
            'odds_over_2_5' => $oddsOu['Over'] ?? null,
            'odds_under_2_5' => $oddsOu['Under'] ?? null,
        ]);
        $fm->setRelation('advancedData', null);
        return $fm;
    }

    private function triplet(HistoricalMatch $m, string $prefix): ?array
    {
        $t = ['1' => $m->{"{$prefix}_home"}, 'X' => $m->{"{$prefix}_draw"}, '2' => $m->{"{$prefix}_away"}];
        foreach ($t as $v) {
            if ($v === null || (float) $v <= 1.0) {
                return null;
            }
        }
        return array_map('floatval', $t);
    }

    private function pair(HistoricalMatch $m, string $prefix): ?array
    {
        $p = ['Over' => $m->{"{$prefix}_over25"}, 'Under' => $m->{"{$prefix}_under25"}];
        foreach ($p as $v) {
            if ($v === null || (float) $v <= 1.0) {
                return null;
            }
        }
        return array_map('floatval', $p);
    }

    /** Démarginalisation proportionnelle : chaque ensemble complet normalisé à 1. */
    private function fairTriplet(?array $odds): ?array
    {
        return $odds === null ? null : $this->normalize($odds);
    }

    private function fairPair(?array $odds): ?array
    {
        return $odds === null ? null : $this->normalize($odds);
    }

    private function normalize(array $odds): array
    {
        $raw = array_map(fn (float $o) => 1 / $o, $odds);
        $sum = array_sum($raw);
        return array_map(fn (float $p) => $p / $sum, $raw);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PERSISTANCE + EXPORT
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function storeRows(BacktestFdRun $run, array $rows): void
    {
        if (empty($rows)) {
            return;
        }
        $payload = array_map(fn ($r) => $r + ['run_id' => $run->id], $rows);
        BacktestFdPrediction::insert($payload);
    }

    private function export(BacktestFdRun $run, array $config, array $evaluation, array $warnings): string
    {
        $label = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($config['label'] ?? $config['sample']));
        $path = "backtest/run_{$run->id}_{$label}.json";

        $payload = [
            'run_id' => $run->id,
            'generated_at' => now()->toIso8601String(),
            'config' => $config,
            'model' => ['service' => XGModelService::class, 'mode' => 'market_only', 'input' => 'opening odds only, closing odds never fed'],
            'matches_loaded' => $evaluation['matches_by_season'],
            'matches_evaluated' => $evaluation['matches_evaluated'],
            'exclusions' => $evaluation['exclusions'],
            'warnings' => $warnings,
            'independent_derived_markets' => self::INDEPENDENT_DERIVED_MARKETS,
            'results' => $evaluation['results'],
        ];

        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
