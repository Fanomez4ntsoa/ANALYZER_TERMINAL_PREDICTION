<?php

namespace App\Services\Probability;

use App\Services\Backtesting\ScopesToPriorWorkSeasons;
use App\Services\Backtesting\SeasonScopedEstimator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moyenne de buts par match (fthg + ftag) par ligue, depuis historical_matches.
 *
 * Saisons utilisées : saisons de travail de config('football-data') uniquement,
 * jamais les saisons réservées. Pendant un backtest (SeasonScopedEstimator), seules
 * les saisons de travail STRICTEMENT antérieures à celle du match évalué comptent ;
 * sans saison antérieure disponible, aucune estimation (null).
 *
 * Clé : id de ligue API-Football (config football-data.league_ids inversée).
 */
class LeagueGoalAverages implements SeasonScopedEstimator
{
    use ScopesToPriorWorkSeasons;

    /** @var array<string, array<int, array{goals: float, matches: int}>>|null  saison → ligue → sommes */
    private ?array $bySeason;

    /** @param array<string, array<int, array{goals: float, matches: int}>>|null $preset */
    public function __construct(?array $preset = null)
    {
        $this->bySeason = $preset;
    }

    public function totalFor(?int $leagueId): ?float
    {
        $this->assertScopeIfRequired();
        if ($leagueId === null) {
            return null;
        }
        $this->load();

        $goals = 0.0;
        $matches = 0;
        $seasons = array_values(array_filter($this->allowedSeasons(), fn ($s) => isset($this->bySeason[$s][$leagueId])));
        $this->assertTrainingSeasons($seasons);
        foreach ($seasons as $season) {
            if (isset($this->bySeason[$season][$leagueId])) {
                $goals += $this->bySeason[$season][$leagueId]['goals'];
                $matches += $this->bySeason[$season][$leagueId]['matches'];
            }
        }

        return $matches > 0 ? round($goals / $matches, 3) : null;
    }

    private function load(): void
    {
        if ($this->bySeason !== null) {
            return;
        }
        $this->bySeason = [];

        if (!Schema::hasTable('historical_matches')) {
            return;
        }

        $divToLeague = config('football-data.league_ids', []);
        $rows = DB::table('historical_matches')
            ->whereIn('season', config('football-data.work_seasons', []))
            ->whereNotNull('fthg')->whereNotNull('ftag')
            ->selectRaw('season, `div` as d, sum(fthg + ftag) as goals, count(*) as n')
            ->groupBy('season', 'div')
            ->get();

        foreach ($rows as $r) {
            if (isset($divToLeague[$r->d])) {
                $this->bySeason[$r->season][$divToLeague[$r->d]] = ['goals' => (float) $r->goals, 'matches' => (int) $r->n];
            }
        }
    }
}
