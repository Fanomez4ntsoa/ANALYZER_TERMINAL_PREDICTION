<?php

namespace App\Services\Probability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moyenne de buts par match (fthg + ftag) par ligue, calculée depuis
 * historical_matches sur les saisons de travail de config('football-data')
 * uniquement. Les saisons réservées n'entrent jamais dans ce calcul.
 *
 * Clé : id de ligue API-Football (config football-data.league_ids inversée).
 * Chargée une fois par processus ; un tableau préchargé peut être injecté
 * (tests, ou absence de base).
 */
class LeagueGoalAverages
{
    /** @var array<int, float>|null */
    private ?array $byLeagueId;

    /** @param array<int, float>|null $preset */
    public function __construct(?array $preset = null)
    {
        $this->byLeagueId = $preset;
    }

    public function totalFor(?int $leagueId): ?float
    {
        if ($leagueId === null) {
            return null;
        }
        $this->load();

        return $this->byLeagueId[$leagueId] ?? null;
    }

    /** @return array<int, float> */
    public function all(): array
    {
        $this->load();

        return $this->byLeagueId;
    }

    private function load(): void
    {
        if ($this->byLeagueId !== null) {
            return;
        }
        $this->byLeagueId = [];

        if (!Schema::hasTable('historical_matches')) {
            return;
        }

        $divToLeague = config('football-data.league_ids', []);
        $rows = DB::table('historical_matches')
            ->whereIn('season', config('football-data.work_seasons', []))
            ->whereNotNull('fthg')->whereNotNull('ftag')
            ->selectRaw('`div` as d, avg(fthg + ftag) as g, count(*) as n')
            ->groupBy('div')
            ->get();

        foreach ($rows as $r) {
            if (isset($divToLeague[$r->d]) && $r->n > 0) {
                $this->byLeagueId[$divToLeague[$r->d]] = round((float) $r->g, 3);
            }
        }
    }
}
