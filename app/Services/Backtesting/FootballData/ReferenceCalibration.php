<?php

namespace App\Services\Backtesting\FootballData;

use App\Models\BacktestFdRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calibration du run de référence (football-data.reference_run), lue sur une seule
 * population et des saisons fixées, pour le panneau de l'interface.
 *
 * Ce n'est jamais une mesure des prédictions du jour : c'est un backtest historique
 * sur cotes d'ouverture. Le résumé porte la configuration mesurée, la population,
 * les saisons et l'effectif de chaque tranche, et signale tout écart entre la
 * configuration du run et celle de la production.
 */
class ReferenceCalibration
{
    private const BIN_WIDTH = 0.05;

    /** Familles et marchés affichés : ceux que la production publie. */
    private const MARKETS = [
        ['adjustment', 'winner', '1X2'],
        ['adjustment', 'overUnder25', 'Over/Under 2.5'],
        ['derived', 'doubleChance', 'Double chance'],
        ['derived', 'btts', 'Les deux marquent'],
    ];

    /** Correspondance bookmaker d'entrée du backtest → id API-Football de production. */
    private const INPUT_TO_API_FOOTBALL = ['b365' => 8, 'ps' => 4];

    /**
     * @return array|null null si le run de référence est introuvable ou non terminé
     */
    public function summary(): ?array
    {
        $ref = config('football-data.reference_run');
        $run = BacktestFdRun::find($ref['id']);

        if ($run === null || $run->status !== 'completed') {
            return null;
        }

        $population = config("football-data.populations.{$ref['population']}");
        $seasons = array_values(array_intersect($ref['seasons'], $run->seasons));

        $markets = Cache::rememberForever(
            "reference_calibration_{$run->id}_{$ref['population']}_" . implode('-', $seasons),
            fn () => $this->aggregate($run, $population['divisions'], $seasons)
        );

        return [
            'run_id' => $run->id,
            'run_label' => $run->label,
            'finished_at' => $run->finished_at,
            'sample' => $run->sample,
            'input' => $run->input_bookmaker . ' ouverture',
            'model' => $run->config['model'] ?? [],
            'population' => $population['label'],
            'divisions' => $population['divisions'],
            'seasons' => $seasons,
            'markets' => $markets,
            'config_mismatches' => $this->configMismatches($run),
        ];
    }

    /**
     * Écarts entre la configuration mesurée par le run et celle de la production.
     *
     * @return string[] une ligne lisible par écart, vide si identiques
     */
    public function configMismatches(BacktestFdRun $run): array
    {
        $measured = $run->config['model'] ?? [];
        $mismatches = [];

        foreach (['legacy_home_advantage_after_fusion', 'legacy_constant_share_rescaling', 'anchor_total_on_league_average', 'dixon_coles_low_score_correction', 'dixon_coles_rho_scope'] as $key) {
            $production = config("xg-model.{$key}");
            if (($measured[$key] ?? null) !== $production) {
                $mismatches[] = "{$key} : run " . json_encode($measured[$key] ?? null) . ', production ' . json_encode($production);
            }
        }

        $inputId = self::INPUT_TO_API_FOOTBALL[$run->input_bookmaker] ?? null;
        $productionBookmaker = (int) config('api-football.preferred_bookmaker');
        if ($inputId !== $productionBookmaker) {
            $mismatches[] = "bookmaker d'entrée : run {$run->input_bookmaker}, production id API-Football {$productionBookmaker}";
        }

        return $mismatches;
    }

    private function aggregate(BacktestFdRun $run, array $divisions, array $seasons): array
    {
        $bins = (int) round(1 / self::BIN_WIDTH);
        $rows = DB::table('backtest_fd_predictions')
            ->where('run_id', $run->id)
            ->whereIn('div', $divisions)
            ->whereIn('season', $seasons)
            ->selectRaw("family, market, least(floor(model_probability * {$bins}), " . ($bins - 1) . ") bin, count(*) n,
                sum(model_probability) sum_p, sum(observed) sum_y,
                sum(pow(model_probability - observed, 2)) sse_m,
                sum(pinnacle_close_fair is not null) n_ref,
                sum(if(pinnacle_close_fair is not null, pow(model_probability - observed, 2), 0)) sse_m_ref,
                sum(if(pinnacle_close_fair is not null, pow(pinnacle_close_fair - observed, 2), 0)) sse_ref")
            ->groupBy('family', 'market', 'bin')
            ->get();

        $out = [];
        foreach (self::MARKETS as [$family, $market, $label]) {
            $cells = $rows->where('family', $family)->where('market', $market)->sortBy('bin');
            $n = (int) $cells->sum('n');
            $nRef = (int) $cells->sum('n_ref');

            $out[] = [
                'family' => $family,
                'market' => $market,
                'label' => $label,
                'n' => $n,
                'brier_model' => $n > 0 ? round($cells->sum('sse_m') / $n, 5) : null,
                'brier_model_on_reference_subset' => $nRef > 0 ? round($cells->sum('sse_m_ref') / $nRef, 5) : null,
                'brier_pinnacle_close' => $nRef > 0 ? round($cells->sum('sse_ref') / $nRef, 5) : null,
                'bins' => $cells->map(fn ($c) => [
                    'from' => round($c->bin * self::BIN_WIDTH, 2),
                    'to' => round(($c->bin + 1) * self::BIN_WIDTH, 2),
                    'n' => (int) $c->n,
                    'mean_model' => round($c->sum_p / $c->n, 4),
                    'observed' => round($c->sum_y / $c->n, 4),
                ])->values()->all(),
            ];
        }

        return $out;
    }
}
