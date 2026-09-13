<?php

namespace App\Services\Probability;

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
    /** @var array<string, array<int, array{goals: float, matches: int}>>|null  saison → ligue → sommes */
    private ?array $bySeason;

    private ?string $scopeSeason = null;
    private bool $scopeRequired = false;

    /** @param array<string, array<int, array{goals: float, matches: int}>>|null $preset */
    public function __construct(?array $preset = null)
    {
        $this->bySeason = $preset;
    }

    public function scopeToSeasonsBefore(?string $season): void
    {
        $this->scopeSeason = $season;
    }

    public function requireScope(bool $required): void
    {
        $this->scopeRequired = $required;
    }

    public function isScoped(): bool
    {
        return $this->scopeSeason !== null;
    }

    public function totalFor(?int $leagueId): ?float
    {
        if ($this->scopeRequired && $this->scopeSeason === null) {
            throw new \LogicException('LeagueGoalAverages lu sans bornage de saison pendant un backtest : fuite temporelle refusée.');
        }
        if ($leagueId === null) {
            return null;
        }
        $this->load();

        $goals = 0.0;
        $matches = 0;
        foreach ($this->allowedSeasons() as $season) {
            if (isset($this->bySeason[$season][$leagueId])) {
                $goals += $this->bySeason[$season][$leagueId]['goals'];
                $matches += $this->bySeason[$season][$leagueId]['matches'];
            }
        }

        return $matches > 0 ? round($goals / $matches, 3) : null;
    }

    /** @return string[] */
    public function allowedSeasons(): array
    {
        $work = config('football-data.work_seasons', []);
        if ($this->scopeSeason === null) {
            return $work;
        }

        // Saisons AABB : l'ordre lexicographique est l'ordre chronologique.
        return array_values(array_filter($work, fn (string $s) => strcmp($s, $this->scopeSeason) < 0));
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
