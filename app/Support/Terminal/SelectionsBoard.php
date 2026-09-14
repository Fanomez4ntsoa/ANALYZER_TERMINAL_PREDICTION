<?php

namespace App\Support\Terminal;

use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Services\Probability\PoissonModelService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Tableau des sélections d'une journée : tous les matchs importés, triés par
 * heure du match, avec leurs prédictions dans l'ordre fixe du catalogue.
 *
 * Un match sans prédiction reste affiché avec sa raison : jamais omis. « Hors
 * périmètre » exige l'absence de cotes : un match ancien relevé quand le
 * périmètre était plus large a des cotes, il n'est pas hors périmètre.
 */
class SelectionsBoard
{
    public const PRICED = 'priced';
    public const OUT_OF_PERIMETER = 'out_of_perimeter';
    public const NO_ODDS = 'no_odds';
    public const KICKED_OFF_UNCOMPUTED = 'kicked_off_uncomputed';
    public const NOT_COMPUTED = 'not_computed';

    public function __construct(private PoissonModelService $poisson)
    {
    }

    public function forDate(string $date): array
    {
        $matches = FootballMatch::query()
            ->where('data_source', 'api')
            ->whereDate('match_date', $date)
            ->with('predictions')
            ->orderBy('match_date')
            ->orderBy('id')
            ->get();

        return self::build($matches, config('api-football.odds_leagues', []), CarbonImmutable::now('UTC'), $this->poisson);
    }

    /**
     * Logique pure sur des modèles déjà chargés.
     *
     * @param  Collection<int, FootballMatch>  $matches  triés par heure du match
     * @param  int[]  $oddsLeagues
     * @return array{groups: array, simulations: array, counts: array}
     */
    public static function build(Collection $matches, array $oddsLeagues, CarbonImmutable $now, ?PoissonModelService $poisson = null): array
    {
        $groups = [];
        $simulations = [];
        $counts = ['matches' => 0, 'priced' => 0, 'lines' => 0];

        foreach ($matches as $match) {
            $counts['matches']++;
            $kickedOff = $match->match_date !== null && $match->match_date->lte($now);
            $predictions = $match->predictions
                ->sortBy(fn (Prediction $p) => MarketLabel::sortKey($p->market, $p->outcome))
                ->values();

            if ($predictions->isEmpty()) {
                $hasOdds = (float) $match->odds_home > 0;
                $inPerimeter = in_array((int) $match->league_id, array_map('intval', $oddsLeagues), true);
                $status = match (true) {
                    !$hasOdds && !$inPerimeter => self::OUT_OF_PERIMETER,
                    $kickedOff => self::KICKED_OFF_UNCOMPUTED,
                    !$hasOdds => self::NO_ODDS,
                    default => self::NOT_COMPUTED,
                };
            } else {
                $status = self::PRICED;
                $counts['priced']++;
            }

            $rows = $predictions->map(fn (Prediction $p) => [
                'id' => $p->id,
                'market' => $p->market,
                'tag' => MarketLabel::tag($p->market, $p->outcome),
                'probability' => $p->model_probability,
                'odds' => $p->odds,
                'implied' => $p->implied_probability,
                'edge' => $p->odds === null ? null : $p->edge,
                'pickable' => $p->odds !== null && !$kickedOff,
            ])->all();
            $counts['lines'] += count($rows);

            $groups[] = [
                'match_id' => $match->id,
                'kickoff' => $match->match_date?->copy()->utc()->format('H:i'),
                'home' => $match->home_team,
                'away' => $match->away_team,
                'competition' => $match->competition,
                'kicked_off' => $kickedOff,
                'status' => $status,
                'rows' => $rows,
            ];

            $simulation = self::simulation($match, $predictions, $poisson);
            if ($simulation !== null) {
                $simulations[$match->id] = $simulation;
            }
        }

        return ['groups' => $groups, 'simulations' => $simulations, 'counts' => $counts];
    }

    /**
     * Paramètres de la simulation Monte-Carlo : λ et ρ enregistrés avec la
     * prédiction affichée, jamais recalculés, et la matrice exacte du modèle
     * (0 à 6 buts) sur laquelle les tirages sont faits.
     */
    private static function simulation(FootballMatch $match, Collection $predictions, ?PoissonModelService $poisson): ?array
    {
        $first = $predictions->first();
        if ($poisson === null || $first === null || $first->lambda_home === null || $first->lambda_away === null) {
            return null;
        }

        $recorded = fn (string $market, string $outcome) => $predictions
            ->first(fn (Prediction $p) => $p->market === $market && $p->outcome === $outcome)
            ?->model_probability;

        $matrix = $poisson->scoreMatrix($first->lambda_home, $first->lambda_away, $first->rho);

        return [
            'match_id' => $match->id,
            'label' => "{$match->home_team} · {$match->away_team}",
            'home' => $match->home_team,
            'lambda_home' => $first->lambda_home,
            'lambda_away' => $first->lambda_away,
            'rho' => $first->rho,
            'params' => 'λ ' . Fmt::number($first->lambda_home, 3) . ' / ' . Fmt::number($first->lambda_away, 3)
                . ($first->rho !== null ? ' · ρ ' . Fmt::number($first->rho, 3) : ''),
            'matrix' => array_map(fn (array $row) => array_map(fn (float $p) => round($p, 7), array_values($row)), array_values($matrix)),
            'recorded_over25' => $recorded(Prediction::MARKET_OVER_UNDER_25, 'Over'),
            'recorded_home' => $recorded(Prediction::MARKET_WINNER, '1'),
        ];
    }
}
