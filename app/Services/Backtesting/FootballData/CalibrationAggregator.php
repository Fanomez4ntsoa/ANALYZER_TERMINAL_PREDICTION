<?php

namespace App\Services\Backtesting\FootballData;

/**
 * Agrégation des lignes de prédiction en métriques de calibration :
 *  - Brier du modèle ; Brier de la référence Pinnacle clôture sur les mêmes lignes ;
 *  - écart quadratique moyen modèle vs référence ;
 *  - fréquence observée par tranche de probabilité annoncée (avec effectif).
 * Segmentation : famille → marché → global / par division / par saison.
 * Aucune métrique financière.
 */
class CalibrationAggregator
{
    private array $cells = [];

    public function __construct(private float $binWidth = 0.05)
    {
    }

    public function add(array $row): void
    {
        $f = $row['family'];
        $m = $row['market'];
        $this->accumulate("{$f}|{$m}|all|all", $row);
        $this->accumulate("{$f}|{$m}|div|{$row['div']}", $row);
        $this->accumulate("{$f}|{$m}|season|{$row['season']}", $row);
        $this->accumulate("{$f}|{$m}|outcome|{$row['outcome']}", $row);
    }

    private function accumulate(string $key, array $row): void
    {
        if (!isset($this->cells[$key])) {
            $this->cells[$key] = [
                'n' => 0,
                'sum_model_sq_err' => 0.0,
                'n_ref' => 0,
                'sum_model_sq_err_on_ref' => 0.0,
                'sum_ref_sq_err' => 0.0,
                'sum_model_vs_ref_sq' => 0.0,
                'bins' => [],
            ];
        }
        $c = &$this->cells[$key];

        $p = (float) $row['model_probability'];
        $y = $row['observed'] ? 1.0 : 0.0;
        $ref = $row['pinnacle_close_fair'];

        $c['n']++;
        $c['sum_model_sq_err'] += ($p - $y) ** 2;

        if ($ref !== null) {
            $ref = (float) $ref;
            $c['n_ref']++;
            $c['sum_model_sq_err_on_ref'] += ($p - $y) ** 2;
            $c['sum_ref_sq_err'] += ($ref - $y) ** 2;
            $c['sum_model_vs_ref_sq'] += ($p - $ref) ** 2;
        }

        $bin = min((int) floor($p / $this->binWidth), (int) round(1 / $this->binWidth) - 1);
        if (!isset($c['bins'][$bin])) {
            $c['bins'][$bin] = ['n' => 0, 'sum_p' => 0.0, 'sum_y' => 0.0, 'n_ref' => 0, 'sum_ref' => 0.0];
        }
        $b = &$c['bins'][$bin];
        $b['n']++;
        $b['sum_p'] += $p;
        $b['sum_y'] += $y;
        if ($ref !== null) {
            $b['n_ref']++;
            $b['sum_ref'] += $ref;
        }
    }

    public function results(): array
    {
        $out = [];
        foreach ($this->cells as $key => $c) {
            [$family, $market, $scope, $scopeKey] = explode('|', $key, 4);
            $metrics = $this->metrics($c);
            if ($scope === 'all') {
                $out[$family][$market]['overall'] = $metrics;
            } else {
                $scopeName = ['div' => 'by_division', 'season' => 'by_season', 'outcome' => 'by_outcome'][$scope];
                $out[$family][$market][$scopeName][$scopeKey] = $metrics;
            }
        }

        foreach ($out as &$markets) {
            ksort($markets);
            foreach ($markets as &$m) {
                foreach (['by_division', 'by_season', 'by_outcome'] as $s) {
                    if (isset($m[$s])) {
                        ksort($m[$s]);
                    }
                }
            }
        }

        return $out;
    }

    private function metrics(array $c): array
    {
        $bins = [];
        ksort($c['bins']);
        foreach ($c['bins'] as $i => $b) {
            $bins[] = [
                'from' => round($i * $this->binWidth, 2),
                'to' => round(($i + 1) * $this->binWidth, 2),
                'n' => $b['n'],
                'mean_model' => round($b['sum_p'] / $b['n'], 4),
                'observed' => round($b['sum_y'] / $b['n'], 4),
                'mean_pinnacle_close' => $b['n_ref'] > 0 ? round($b['sum_ref'] / $b['n_ref'], 4) : null,
            ];
        }

        return [
            'n' => $c['n'],
            'brier_model' => round($c['sum_model_sq_err'] / max(1, $c['n']), 5),
            'n_with_reference' => $c['n_ref'],
            'brier_model_on_reference_subset' => $c['n_ref'] > 0 ? round($c['sum_model_sq_err_on_ref'] / $c['n_ref'], 5) : null,
            'brier_pinnacle_close' => $c['n_ref'] > 0 ? round($c['sum_ref_sq_err'] / $c['n_ref'], 5) : null,
            'mse_model_vs_pinnacle_close' => $c['n_ref'] > 0 ? round($c['sum_model_vs_ref_sq'] / $c['n_ref'], 5) : null,
            'calibration_bins' => $bins,
        ];
    }
}
