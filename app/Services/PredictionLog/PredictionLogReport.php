<?php

namespace App\Services\PredictionLog;

use App\Models\FootballMatch;
use App\Models\PipelineRun;
use App\Models\PredictionLogEntry;
use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mesure du journal des sélections sur matchs réels.
 *
 * Lignes mesurées : le premier calcul du pipeline de chaque match (de chaque coup
 * d'envoi, pour un match reprogrammé), et lui seul, clôturé, sur un match non
 * contaminé. Le rapport ne dépend en rien de ce que l'utilisateur consulte : les
 * lignes du bouton sont clôturées mais jamais mesurées, et les matchs qui n'ont que
 * celles-là sont comptés à part. Les lignes non clôturables (void_reason) ne sont ni
 * mesurées ni en attente : comptées à part, par raison.
 *
 * Les absences se lisent aussi : jours sans passage du pipeline depuis le début du
 * journal. Leurs matchs n'ont jamais été importés ni calculés, aucun autre compteur
 * ne les voit, et ils ne se rattrapent pas (règle 5).
 *
 * Métriques par marché, tous championnats puis par championnat : effectif en matchs
 * et en lignes, Brier du modèle marché seul, Brier de la probabilité équitable
 * Bet365 sur les mêmes lignes, différence appariée avec erreur type groupée par
 * match (les issues d'un match ne sont pas indépendantes), fréquence observée par
 * tranche de probabilité annoncée. Mêmes métriques pour le modèle complet, sur les
 * seules lignes où un signal hors marché a servi, avec sa propre progression vers
 * le seuil.
 *
 * Aucune métrique financière, aucun verdict, aucun tri autre que marché et
 * championnat. closing_edge n'est pas agrégé.
 */
class PredictionLogReport
{
    public function build(?string $market = null, ?int $leagueId = null): array
    {
        // Configuration absente (cache antérieur au fichier) : un seuil nul
        // déclarerait tous les effectifs suffisants
        $threshold = config('prediction-log.min_matches');
        if (!is_int($threshold) || $threshold < 1 || !is_array(config('prediction-log.groups'))) {
            throw new \RuntimeException('Configuration prediction-log absente ou invalide : relancer php artisan config:cache');
        }
        $filter = function ($query, string $table = 'prediction_log') use ($market, $leagueId) {
            if ($market !== null) {
                $query->where("{$table}.market", $market);
            }
            if ($leagueId !== null) {
                $query->where("{$table}.league_id", $leagueId);
            }

            return $query;
        };

        // Premier calcul du pipeline de chaque match et coup d'envoi : toutes ses
        // lignes partagent le même computed_at. Par coup d'envoi : les lignes d'un
        // match reprogrammé, non clôturables, ne masquent pas celles de la nouvelle date.
        $firstPipeline = DB::table('prediction_log')
            ->select('match_id', 'kickoff_at', DB::raw('MIN(computed_at) as first_computed_at'))
            ->where('trigger', PredictionLogEntry::TRIGGER_PIPELINE)
            ->groupBy('match_id', 'kickoff_at');

        $first = $filter(PredictionLogEntry::query()
            ->select('prediction_log.*', 'matches.competition', 'matches.post_kickoff_data')
            ->joinSub($firstPipeline, 'first_pipeline', function ($join) {
                $join->on('first_pipeline.match_id', '=', 'prediction_log.match_id')
                    ->on('first_pipeline.kickoff_at', '=', 'prediction_log.kickoff_at')
                    ->on('first_pipeline.first_computed_at', '=', 'prediction_log.computed_at');
            })
            ->join('matches', 'matches.id', '=', 'prediction_log.match_id')
            ->where('prediction_log.trigger', PredictionLogEntry::TRIGGER_PIPELINE))
            ->orderBy('prediction_log.id')
            ->get();

        $contaminated = $first->filter(fn ($e) => (bool) $e->post_kickoff_data);
        $measurable = $first->reject(fn ($e) => (bool) $e->post_kickoff_data);
        $settled = $measurable->filter(fn ($e) => $e->outcome_occurred !== null);
        $voided = $measurable->filter(fn ($e) => $e->void_reason !== null);
        $pending = $measurable->filter(fn ($e) => $e->outcome_occurred === null && $e->void_reason === null);

        // Matchs sans aucune ligne du pipeline : lignes du bouton seulement, écartées
        $manualOnly = $filter(PredictionLogEntry::query()
            ->whereNotIn('match_id', DB::table('prediction_log')->select('match_id')->where('trigger', PredictionLogEntry::TRIGGER_PIPELINE)))
            ->get(['match_id', 'outcome_occurred']);

        $leagues = FootballMatch::whereIn('league_id', $settled->pluck('league_id')->filter()->unique())
            ->orderBy('match_date')
            ->pluck('competition', 'league_id') // nom le plus récent
            ->all();

        $groups = [];
        foreach (config('prediction-log.groups') as $groupKey => $group) {
            $markets = [];
            foreach ($group['markets'] as $groupMarket) {
                if ($market !== null && $market !== $groupMarket) {
                    continue;
                }
                $rows = $settled->where('market', $groupMarket)->values();
                $markets[$groupMarket] = [
                    'model' => $this->section($rows, 'model_probability', $threshold, $leagues),
                    'full' => $this->fullModelSection($rows, $threshold, $leagues),
                ];
            }
            if ($markets) {
                $groups[$groupKey] = $group + ['results' => $markets];
            }
        }

        return [
            'threshold' => $threshold,
            'filters' => ['market' => $market, 'league_id' => $leagueId],
            'totals' => [
                'settled_matches' => $settled->pluck('match_id')->unique()->count(),
                'settled_lines' => $settled->count(),
                'pending_matches' => $pending->pluck('match_id')->unique()->count(),
                'pending_lines' => $pending->count(),
                'voided_matches' => $voided->pluck('match_id')->unique()->count(),
                'voided_lines' => $voided->count(),
                'voided_by_reason' => $voided->countBy('void_reason')->sortKeys()->all(),
                'manual_only_matches' => $manualOnly->pluck('match_id')->unique()->count(),
                'manual_only_lines' => $manualOnly->count(),
                'manual_only_settled_lines' => $manualOnly->whereNotNull('outcome_occurred')->count(),
                'contaminated_matches' => $contaminated->pluck('match_id')->unique()->count(),
            ],
            'groups' => $groups,
            'pipeline_gaps' => $this->pipelineGaps(),
        ];
    }

    /**
     * Jours sans passage du pipeline, du premier calcul du journal à la veille :
     * aucun passage enregistré (missing), ou seulement des passages échoués ou
     * interrompus (failed). Sans filtre de marché ni de championnat : un jour perdu
     * l'est pour tous.
     *
     * @return array{since: ?string, missing: list<string>, failed: list<string>}
     */
    private function pipelineGaps(): array
    {
        $firstComputed = PredictionLogEntry::min('computed_at');
        if ($firstComputed === null) {
            return ['since' => null, 'missing' => [], 'failed' => []];
        }

        $since = Carbon::parse($firstComputed)->startOfDay();
        $until = now()->subDay()->startOfDay();

        // Table minuscule (un passage par jour) : filtrée en PHP, run_date n'a pas le
        // même format stocké sous MariaDB et SQLite
        $runs = PipelineRun::get(['run_date', 'status'])
            ->groupBy(fn (PipelineRun $run) => $run->run_date->format('Y-m-d'));

        $missing = [];
        $failed = [];
        if ($since->lte($until)) {
            foreach (CarbonPeriod::create($since, $until) as $day) {
                $dayRuns = $runs->get($day->format('Y-m-d'));
                if ($dayRuns === null) {
                    $missing[] = $day->format('Y-m-d');
                } elseif (!$dayRuns->contains(fn (PipelineRun $run) => in_array($run->status, [PipelineRun::SUCCESS, PipelineRun::INCOMPLETE], true))) {
                    $failed[] = $day->format('Y-m-d');
                }
            }
        }

        return ['since' => $since->format('Y-m-d'), 'missing' => $missing, 'failed' => $failed];
    }

    /**
     * Métriques d'une probabilité, tous championnats puis par championnat.
     */
    private function section(Collection $rows, string $field, int $threshold, array $leagues, ?string $baseline = null): array
    {
        $byLeague = [];
        foreach ($rows->groupBy(fn ($e) => (int) $e->league_id) as $leagueId => $leagueRows) {
            $byLeague[$leagueId] = ['league' => $leagues[$leagueId] ?? "ligue {$leagueId}"]
                + $this->metrics($leagueRows, $field, $threshold, $baseline);
        }
        uasort($byLeague, fn ($a, $b) => strcmp($a['league'], $b['league']));

        return [
            'overall' => $this->metrics($rows, $field, $threshold, $baseline),
            'by_league' => $byLeague,
        ];
    }

    /**
     * Modèle complet : seules les lignes où comparaison ou blessures ont servi.
     * Ailleurs il reproduit exactement le marché seul, et le comparer diluerait
     * l'écart. Comparé au marché seul sur les mêmes lignes.
     */
    private function fullModelSection(Collection $rows, int $threshold, array $leagues): array
    {
        $extra = config('prediction-log.full_model_extra_signals');
        $withFull = $rows->filter(fn ($e) => $e->full_model_probability !== null);
        $used = $withFull->filter(fn ($e) => array_intersect($extra, $e->full_model_signals['used'] ?? []) !== []);
        $withoutExtra = $withFull->diffKeys($used);

        return $this->section($used->values(), 'full_model_probability', $threshold, $leagues, 'model_probability') + [
            'market_only_matches' => $rows->pluck('match_id')->unique()->count(),
            'without_extra_signal_matches' => $withoutExtra->pluck('match_id')->unique()->count(),
            'not_computed_matches' => $rows->diffKeys($withFull)->pluck('match_id')->unique()->count(),
        ];
    }

    /**
     * @param string|null $baseline second modèle comparé sur les mêmes lignes
     *                              (marché seul, pour le modèle complet)
     */
    private function metrics(Collection $rows, string $field, int $threshold, ?string $baseline = null): array
    {
        $matches = $rows->pluck('match_id')->unique()->count();
        $n = $rows->count();

        $metrics = [
            'matches' => $matches,
            'lines' => $n,
            'threshold_reached' => $matches >= $threshold,
            'brier' => $this->brier($rows, $field),
            'brier_fair' => $this->brier($rows, 'fair_probability'),
            'diff_vs_fair' => $this->pairedDifference($rows, $field, 'fair_probability'),
            'bins' => $this->bins($rows, $field),
        ];

        if ($baseline !== null) {
            $metrics['brier_baseline'] = $this->brier($rows, $baseline);
            $metrics['diff_vs_baseline'] = $this->pairedDifference($rows, $field, $baseline);
        }

        return $metrics;
    }

    private function brier(Collection $rows, string $field): ?float
    {
        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->avg(fn ($e) => ($e->{$field} - ($e->outcome_occurred ? 1 : 0)) ** 2);
    }

    /**
     * Différence de Brier appariée a − b, par ligne, et son erreur type groupée par
     * match : les lignes d'un même match (1, X, 2) sont liées, les compter comme
     * indépendantes sous-estimerait l'erreur.
     *
     * @return array{mean: float, se: ?float}|null
     */
    private function pairedDifference(Collection $rows, string $a, string $b): ?array
    {
        $n = $rows->count();
        if ($n === 0) {
            return null;
        }

        $diff = fn ($e) => ($e->{$a} - ($e->outcome_occurred ? 1 : 0)) ** 2 - ($e->{$b} - ($e->outcome_occurred ? 1 : 0)) ** 2;
        $mean = $rows->sum($diff) / $n;

        $clusters = $rows->groupBy('match_id');
        $g = $clusters->count();
        if ($g < 2) {
            return ['mean' => $mean, 'se' => null];
        }

        $sumSquares = $clusters->sum(fn (Collection $lines) => ($lines->sum($diff) - $lines->count() * $mean) ** 2);

        return ['mean' => $mean, 'se' => sqrt($g / ($g - 1) * $sumSquares) / $n];
    }

    /**
     * Fréquence observée par tranche de probabilité annoncée (dernière tranche
     * fermée à 1), avec effectif, moyenne annoncée et moyenne équitable Bet365.
     */
    private function bins(Collection $rows, string $field): array
    {
        $width = (float) config('prediction-log.bin_width');
        $last = (int) round(1 / $width) - 1;

        return $rows->groupBy(fn ($e) => min((int) floor(round($e->{$field} / $width, 9)), $last))
            ->sortKeys()
            ->map(fn (Collection $bin, int $i) => [
                'from' => round($i * $width, 2),
                'to' => round(($i + 1) * $width, 2),
                'lines' => $bin->count(),
                'matches' => $bin->pluck('match_id')->unique()->count(),
                'announced' => $bin->avg($field),
                'observed' => $bin->avg(fn ($e) => $e->outcome_occurred ? 1 : 0),
                'fair' => $bin->avg('fair_probability'),
            ])
            ->values()
            ->all();
    }
}
