<?php

namespace App\Services\Probability;

use App\Services\Backtesting\ScopesToPriorWorkSeasons;
use App\Services\Backtesting\SeasonScopedEstimator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètre ρ de Dixon-Coles, estimé par population (config football-data.populations).
 *
 * Estimation par maximum de vraisemblance sur les SCORES OBSERVÉS uniquement, jamais
 * sur les cotes : seules les colonnes saison, division, équipes et buts sont lues.
 * Modèle de Dixon & Coles (1997), sans pondération temporelle, une force d'attaque
 * et de défense par équipe et un avantage domicile par championnat-saison :
 *
 *   λ = A[dom] · D[ext] · H[championnat-saison]     μ = A[ext] · D[dom]
 *   P(x, y) = τ(x, y; λ, μ, ρ) · Poisson(x; λ) · Poisson(y; μ)
 *
 * Maximisation alternée jusqu'à convergence : à ρ fixé, forces par point fixe des
 * équations de score (buts ajustés x + ∂log τ/∂log λ) ; à forces fixées, ρ par
 * dichotomie sur la dérivée de Σ log τ, concave en ρ.
 *
 * Saisons d'estimation : saisons de travail, strictement antérieures à la saison du
 * match pendant un backtest (SeasonScopedEstimator). Sans saison antérieure ou avec
 * moins de MIN_MATCHES matchs : pas d'estimation, pas de correction.
 */
class DixonColesRho implements SeasonScopedEstimator
{
    use ScopesToPriorWorkSeasons;

    public const MIN_MATCHES = 500;
    private const CACHE_VERSION = 'v1';

    /** @var array<int, array{season: string, div: string, home_team: string, away_team: string, fthg: int, ftag: int}>|null */
    private ?array $presetRows;

    /** @var array<string, array>  clé population|saisons → résultat d'estimation */
    private array $fits = [];

    /** @param array<int, array{season: string, div: string, home_team: string, away_team: string, fthg: int, ftag: int}>|null $presetRows */
    public function __construct(?array $presetRows = null)
    {
        $this->presetRows = $presetRows;
    }

    public function rhoFor(?int $leagueId): ?float
    {
        $this->assertScopeIfRequired();
        if ($leagueId === null) {
            return null;
        }
        $div = array_search($leagueId, config('football-data.league_ids', []), true);

        return $div === false ? null : $this->rhoForDivision((string) $div);
    }

    public function rhoForDivision(string $div): ?float
    {
        $this->assertScopeIfRequired();
        $population = $this->populationOf($div);
        if ($population === null) {
            return null;
        }

        return $this->fit($population)['rho'];
    }

    /** Estimations calculées pendant la vie du processus (diagnostic et rapport). */
    public function fits(): array
    {
        return array_values($this->fits);
    }

    /**
     * Estimation pour une population sur les saisons autorisées courantes.
     *
     * @return array{population: string, seasons: string[], matches: int, league_seasons: int, rho: ?float, se: ?float, ci95: ?array, loglik: ?float, loglik_independent: ?float, lr_statistic: ?float, draw_rate: ?float, iterations: int}
     */
    public function fit(string $population): array
    {
        $seasons = $this->allowedSeasons();
        $key = $population . '|' . implode(',', $seasons);
        if (isset($this->fits[$key])) {
            return $this->fits[$key];
        }

        $divisions = $this->populationDivisions()[$population] ?? [];
        $rows = $seasons === [] ? [] : $this->trainingRows($seasons, $divisions);
        $this->assertTrainingSeasons(array_unique(array_column($rows, 'season')));

        $compute = fn () => $this->estimate($rows);
        $result = $this->presetRows === null && $rows !== []
            ? Cache::rememberForever('dixon-coles-rho:' . self::CACHE_VERSION . ':' . md5($key . '|' . count($rows) . '|' . $this->fingerprint($rows)), $compute)
            : $compute();

        return $this->fits[$key] = ['population' => $population, 'seasons' => $seasons] + $result;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ estimation

    /**
     * @param array<int, array{season: string, div: string, home_team: string, away_team: string, fthg: int, ftag: int}> $rows
     */
    public function estimate(array $rows): array
    {
        $n = count($rows);
        $empty = ['matches' => $n, 'league_seasons' => 0, 'rho' => null, 'se' => null, 'ci95' => null, 'loglik' => null, 'loglik_independent' => null, 'lr_statistic' => null, 'draw_rate' => null, 'iterations' => 0];
        if ($n < self::MIN_MATCHES) {
            return $empty;
        }

        // Index : équipes et championnats-saisons
        $teamIdx = [];
        $groupIdx = [];
        $hi = $ai = $gi = $x = $y = [];
        $draws = 0;
        foreach ($rows as $k => $r) {
            $g = $r['season'] . '|' . $r['div'];
            $groupIdx[$g] ??= count($groupIdx);
            $th = $g . '|' . $r['home_team'];
            $ta = $g . '|' . $r['away_team'];
            $teamIdx[$th] ??= count($teamIdx);
            $teamIdx[$ta] ??= count($teamIdx);
            $hi[$k] = $teamIdx[$th];
            $ai[$k] = $teamIdx[$ta];
            $gi[$k] = $groupIdx[$g];
            $x[$k] = (int) $r['fthg'];
            $y[$k] = (int) $r['ftag'];
            $draws += $x[$k] === $y[$k] ? 1 : 0;
        }
        $nt = count($teamIdx);
        $ng = count($groupIdx);
        $teamGroup = [];
        foreach ($teamIdx as $name => $i) {
            $teamGroup[$i] = $groupIdx[substr($name, 0, strrpos($name, '|'))];
        }

        $strengths = ['A' => array_fill(0, $nt, 1.0), 'D' => array_fill(0, $nt, 1.0), 'H' => array_fill(0, $ng, 1.3)];

        $fitStrengths = function (float $rho, array $s) use ($n, $nt, $ng, $hi, $ai, $gi, $x, $y, $teamGroup): array {
            for ($it = 0; $it < 1000; $it++) {
                $maxChange = 0.0;
                foreach (['A', 'D', 'H'] as $block) {
                    $num = array_fill(0, $block === 'H' ? $ng : $nt, 0.0);
                    $den = $num;
                    for ($k = 0; $k < $n; $k++) {
                        $l = $s['A'][$hi[$k]] * $s['D'][$ai[$k]] * $s['H'][$gi[$k]];
                        $m = $s['A'][$ai[$k]] * $s['D'][$hi[$k]];
                        [$gl, $gm] = self::logTauGradients($x[$k], $y[$k], $l, $m, $rho);
                        $xa = $x[$k] + $gl; // buts domicile ajustés
                        $ya = $y[$k] + $gm; // buts extérieur ajustés
                        if ($block === 'A') {
                            $num[$hi[$k]] += $xa; $den[$hi[$k]] += $l;
                            $num[$ai[$k]] += $ya; $den[$ai[$k]] += $m;
                        } elseif ($block === 'D') {
                            $num[$ai[$k]] += $xa; $den[$ai[$k]] += $l;
                            $num[$hi[$k]] += $ya; $den[$hi[$k]] += $m;
                        } else {
                            $num[$gi[$k]] += $xa; $den[$gi[$k]] += $l;
                        }
                    }
                    foreach ($num as $i => $v) {
                        if ($den[$i] > 0 && $v > 0) {
                            $f = $v / $den[$i];
                            $maxChange = max($maxChange, abs($f - 1));
                            $s[$block][$i] *= $f;
                        }
                    }
                }
                // Identifiabilité : moyenne géométrique des attaques = 1 par championnat-saison
                $logSum = array_fill(0, $ng, 0.0);
                $count = array_fill(0, $ng, 0);
                foreach ($s['A'] as $i => $v) {
                    $logSum[$teamGroup[$i]] += log($v);
                    $count[$teamGroup[$i]]++;
                }
                foreach ($s['A'] as $i => $v) {
                    $c = exp($logSum[$teamGroup[$i]] / $count[$teamGroup[$i]]);
                    $s['A'][$i] = $v / $c;
                    $s['D'][$i] *= $c;
                }
                if ($maxChange < 1e-9) {
                    break;
                }
            }
            return $s;
        };

        $lambdas = function (array $s) use ($n, $hi, $ai, $gi): array {
            $L = $M = [];
            for ($k = 0; $k < $n; $k++) {
                $L[$k] = $s['A'][$hi[$k]] * $s['D'][$ai[$k]] * $s['H'][$gi[$k]];
                $M[$k] = $s['A'][$ai[$k]] * $s['D'][$hi[$k]];
            }
            return [$L, $M];
        };

        $rho = 0.0;
        $iterations = 0;
        for ($outer = 0; $outer < 100; $outer++) {
            $iterations++;
            $strengths = $fitStrengths($rho, $strengths);
            [$L, $M] = $lambdas($strengths);
            $newRho = self::maximizeRho($x, $y, $L, $M);
            $delta = abs($newRho - $rho);
            $rho = $newRho;
            if ($delta < 1e-7) {
                break;
            }
        }
        $strengths = $fitStrengths($rho, $strengths);
        [$L, $M] = $lambdas($strengths);
        $loglik = self::logLikelihood($x, $y, $L, $M, $rho);

        // Information observée pour ρ, forces fixées (approximation conditionnelle)
        $info = 0.0;
        for ($k = 0; $k < $n; $k++) {
            $d = self::dLogTauDRho($x[$k], $y[$k], $L[$k], $M[$k], $rho);
            $info += $d * $d;
        }
        $se = $info > 0 ? 1 / sqrt($info) : null;

        // Vraisemblance du modèle indépendant, forces réestimées à ρ = 0
        $indep = $fitStrengths(0.0, $strengths);
        [$L0, $M0] = $lambdas($indep);
        $loglik0 = self::logLikelihood($x, $y, $L0, $M0, 0.0);

        return [
            'matches' => $n,
            'league_seasons' => $ng,
            'rho' => round($rho, 5),
            'se' => $se === null ? null : round($se, 5),
            'ci95' => $se === null ? null : [round($rho - 1.96 * $se, 4), round($rho + 1.96 * $se, 4)],
            'loglik' => round($loglik, 3),
            'loglik_independent' => round($loglik0, 3),
            'lr_statistic' => round(2 * ($loglik - $loglik0), 3),
            'draw_rate' => round($draws / $n, 4),
            'iterations' => $iterations,
        ];
    }

    /** ∂log τ/∂log λ et ∂log τ/∂log μ au score observé. */
    private static function logTauGradients(int $x, int $y, float $l, float $m, float $rho): array
    {
        if ($x > 1 || $y > 1 || $rho == 0.0) {
            return [0.0, 0.0];
        }
        return match (true) {
            $x === 0 && $y === 0 => [-$l * $m * $rho / (1 - $l * $m * $rho), -$l * $m * $rho / (1 - $l * $m * $rho)],
            $x === 0 && $y === 1 => [$l * $rho / (1 + $l * $rho), 0.0],
            $x === 1 && $y === 0 => [0.0, $m * $rho / (1 + $m * $rho)],
            default => [0.0, 0.0], // τ(1,1) = 1 − ρ ne dépend pas de λ, μ
        };
    }

    private static function dLogTauDRho(int $x, int $y, float $l, float $m, float $rho): float
    {
        return match (true) {
            $x === 0 && $y === 0 => -$l * $m / (1 - $l * $m * $rho),
            $x === 0 && $y === 1 => $l / (1 + $l * $rho),
            $x === 1 && $y === 0 => $m / (1 + $m * $rho),
            $x === 1 && $y === 1 => -1 / (1 - $rho),
            default => 0.0,
        };
    }

    /** ρ maximisant Σ log τ à λ, μ fixés : dichotomie sur la dérivée, décroissante. */
    private static function maximizeRho(array $x, array $y, array $L, array $M): float
    {
        $lo = -1.0;
        $hi = 1.0;
        foreach ($x as $k => $xk) {
            $lo = max($lo, -1 / $L[$k], -1 / $M[$k]);
            $hi = min($hi, 1 / ($L[$k] * $M[$k]));
        }
        $lo += 1e-9;
        $hi -= 1e-9;
        $derivative = function (float $rho) use ($x, $y, $L, $M): float {
            $d = 0.0;
            foreach ($x as $k => $xk) {
                if ($xk <= 1 && $y[$k] <= 1) {
                    $d += self::dLogTauDRho($xk, $y[$k], $L[$k], $M[$k], $rho);
                }
            }
            return $d;
        };
        for ($i = 0; $i < 80; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($derivative($mid) > 0) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return ($lo + $hi) / 2;
    }

    private static function logLikelihood(array $x, array $y, array $L, array $M, float $rho): float
    {
        $ll = 0.0;
        foreach ($x as $k => $xk) {
            $tau = PoissonModelService::tau($xk, $y[$k], $L[$k], $M[$k], $rho);
            $ll += $xk * log($L[$k]) - $L[$k] - self::logFactorial($xk)
                 + $y[$k] * log($M[$k]) - $M[$k] - self::logFactorial($y[$k])
                 + log(max($tau, 1e-300));
        }
        return $ll;
    }

    private static function logFactorial(int $n): float
    {
        static $cache = [0 => 0.0];
        if (!isset($cache[$n])) {
            $cache[$n] = self::logFactorial($n - 1) + log($n);
        }
        return $cache[$n];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ données

    /**
     * Scores observés des saisons et divisions demandées. Aucune cote n'est lue.
     *
     * @return array<int, array{season: string, div: string, home_team: string, away_team: string, fthg: int, ftag: int}>
     */
    protected function trainingRows(array $seasons, array $divisions): array
    {
        if ($this->presetRows !== null) {
            return array_values(array_filter($this->presetRows, fn ($r) => in_array($r['season'], $seasons, true) && in_array($r['div'], $divisions, true)));
        }
        if (!Schema::hasTable('historical_matches')) {
            return [];
        }

        return DB::table('historical_matches')
            ->whereIn('season', $seasons)
            ->whereIn('div', $divisions)
            ->whereNotNull('fthg')->whereNotNull('ftag')
            ->orderBy('id')
            ->get(['season', 'div', 'home_team', 'away_team', 'fthg', 'ftag'])
            ->map(fn ($r) => ['season' => (string) $r->season, 'div' => $r->div, 'home_team' => $r->home_team, 'away_team' => $r->away_team, 'fthg' => (int) $r->fthg, 'ftag' => (int) $r->ftag])
            ->all();
    }

    private function fingerprint(array $rows): string
    {
        $goals = 0;
        foreach ($rows as $r) {
            $goals += $r['fthg'] * 31 + $r['ftag'];
        }
        return (string) $goals;
    }

    /** @return array<string, string[]>  clé de population → divisions */
    private function populationDivisions(): array
    {
        $pops = config('football-data.populations', []);
        $explicit = [];
        foreach ($pops as $p) {
            if ($p['divisions'] !== null) {
                $explicit = array_merge($explicit, $p['divisions']);
            }
        }
        $all = array_keys(config('football-data.league_ids', []));
        $out = [];
        foreach ($pops as $key => $p) {
            $out[$key] = $p['divisions'] ?? array_values(array_diff($all, $explicit));
        }
        return $out;
    }

    private function populationOf(string $div): ?string
    {
        foreach ($this->populationDivisions() as $key => $divs) {
            if (in_array($div, $divs, true)) {
                return $key;
            }
        }
        return null;
    }
}
