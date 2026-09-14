<?php

namespace App\Services\DataPipeline;

use App\Models\AdvancedData;
use App\Models\FootballMatch;
use Illuminate\Support\Facades\Log;

class MatchEnricherService
{
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CRÉATION / MISE À JOUR MATCH
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Créer ou mettre à jour un match à partir des données API-Football.
     * Retourne le FootballMatch créé/mis à jour.
     */
    public function upsertFromApiFootball(array $fixtureData): ?FootballMatch
    {
        $fixture = $fixtureData['fixture'] ?? null;
        $teams = $fixtureData['teams'] ?? null;
        $league = $fixtureData['league'] ?? null;

        if (!$fixture || !$teams || !$league) {
            Log::warning('MatchEnricher: données fixture incomplètes', [
                'has_fixture' => (bool) $fixture,
                'has_teams' => (bool) $teams,
                'has_league' => (bool) $league,
            ]);
            return null;
        }

        $fixtureId = $fixture['id'];

        // Champs fixes mis à jour à chaque passage (pas les cotes — gérées par FetchOddsJob seul)
        $payload = [
            'home_team' => $teams['home']['name'],
            'home_team_id' => $teams['home']['id'],
            'away_team' => $teams['away']['name'],
            'away_team_id' => $teams['away']['id'],
            'match_date' => $fixture['date'],
            'competition' => $league['name'],
            'league_id' => $league['id'],
            'season' => $league['season'] ?? null,
            'data_source' => 'api',
            'score_home' => $fixture['status']['short'] === 'FT' ? ($fixtureData['goals']['home'] ?? null) : null,
            'score_away' => $fixture['status']['short'] === 'FT' ? ($fixtureData['goals']['away'] ?? null) : null,
            'completed' => in_array($fixture['status']['short'], ['FT', 'AET', 'PEN']),
        ];

        $existing = FootballMatch::where('api_football_id', $fixtureId)->first();
        if (!$existing) {
            // Premier import : initialiser les cotes à null (FetchOddsJob les remplira)
            $payload['odds_home'] = null;
            $payload['odds_draw'] = null;
            $payload['odds_away'] = null;
            // Créé après son coup d'envoi (backfill, rattrapage J-1) : contaminé
            // définitivement, exclu de toute mesure. Jamais retiré ensuite.
            $payload['post_kickoff_data'] = !self::isBeforeKickoff($fixtureData);
        }

        $match = FootballMatch::updateOrCreate(
            ['api_football_id' => $fixtureId],
            $payload
        );

        Log::info("MatchEnricher: match #{$fixtureId} upserted", [
            'match_id' => $match->id,
            'match' => "{$teams['home']['name']} vs {$teams['away']['name']}",
            'date' => $fixture['date'],
            'created' => $match->wasRecentlyCreated,
        ]);

        return $match;
    }

    /**
     * Fixture pas encore commencée : statut « à venir » et coup d'envoi futur.
     * Un match reporté, en cours ou terminé ne reçoit plus que son score.
     */
    public static function isBeforeKickoff(array $fixtureData): bool
    {
        $status = $fixtureData['fixture']['status']['short'] ?? null;
        $date = $fixtureData['fixture']['date'] ?? null;

        return in_array($status, ['NS', 'TBD'], true)
            && $date !== null
            && \Carbon\Carbon::parse($date)->isFuture();
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ENRICHISSEMENT COTES — API-Football /odds (source principale)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Enrichir un match avec les cotes API-Football : 1 call → tous marchés/lignes.
     * C'est la source PRINCIPALE des cotes (remplace The Odds API pour les snapshots de cotes).
     *
     * @param FootballMatch $match
     * @param array $parsedOdds Sortie de ApiFootballService::getFixtureOdds()
     */
    public function enrichWithApiFootballOdds(FootballMatch $match, array $parsedOdds): FootballMatch
    {
        $columns = [
            // 1X2
            'odds_home', 'odds_draw', 'odds_away',
            // Over/Under — 4 lignes principales
            'odds_over_1_5', 'odds_under_1_5',
            'odds_over_2_5', 'odds_under_2_5',
            'odds_over_3_5', 'odds_under_3_5',
            'odds_over_4_5', 'odds_under_4_5',
            // BTTS
            'odds_btts_yes', 'odds_btts_no',
            // Double Chance
            'odds_dc_1x', 'odds_dc_12', 'odds_dc_x2',
        ];

        $updates = [];
        foreach ($columns as $col) {
            if (($parsedOdds[$col] ?? null) !== null) {
                $updates[$col] = $parsedOdds[$col];
            }
        }

        if (empty($updates)) {
            Log::debug("MatchEnricher: aucune cote API-Football pour match #{$match->id}");
            return $match;
        }

        // Heure du relevé : frontière legacy_max / bookmaker configuré (cf. PredictionService)
        $updates['odds_fetched_at'] = now();

        $match->update($updates);

        Log::info("MatchEnricher: cotes API-Football enrichies pour match #{$match->id}", [
            'match' => $match->full_name,
            'columns_filled' => count($updates),
            'over_2_5' => $updates['odds_over_2_5'] ?? '-',
            'under_2_5' => $updates['odds_under_2_5'] ?? '-',
            'over_3_5' => $updates['odds_over_3_5'] ?? '-',
            'under_3_5' => $updates['odds_under_3_5'] ?? '-',
            'btts_no' => $updates['odds_btts_no'] ?? '-',
            'dc_1x' => $updates['odds_dc_1x'] ?? '-',
            'bookmakers' => $parsedOdds['bookmaker_count'] ?? 0,
        ]);

        return $match;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // LIAISON The Odds API — CLV tracker uniquement
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Lier un match à son événement The Odds API (odds_api_event_id) pour le CLV tracker.
     *
     * Depuis la simplification (2026-09-13), The Odds API n'écrit plus JAMAIS dans
     * les colonnes odds_* de `matches` : la seule source de cotes est API-Football /odds
     * (enrichWithApiFootballOdds). Les cotes The Odds API vont dans `odds_movements`.
     */
    public function linkOddsApiEvent(FootballMatch $match, array $normalizedOdds): FootballMatch
    {
        if (empty($normalizedOdds['event_id'])) {
            return $match;
        }

        $match->update(['odds_api_event_id' => $normalizedOdds['event_id']]);

        Log::info("MatchEnricher (TheOddsApi/CLV): event lié pour match #{$match->id}", [
            'match' => $match->full_name,
            'event_id' => $normalizedOdds['event_id'],
        ]);

        return $match;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ENRICHISSEMENT DONNÉES AVANCÉES (Layer 2)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Enrichir un match avec les données avancées d'API-Football.
     * Stocke dans la table advanced_data pour Layer 2.
     */
    public function enrichWithAdvancedData(FootballMatch $match, array $fullMatchData): FootballMatch
    {
        $advancedData = [];

        // H2H → format sofascore_data.h2h
        if (!empty($fullMatchData['h2h'])) {
            $advancedData['sofascore_data'] = array_merge(
                $advancedData['sofascore_data'] ?? [],
                ['h2h' => $this->normalizeH2H($fullMatchData['h2h'])]
            );
        }

        // Blessures → format sofascore_data.injuries
        if (!empty($fullMatchData['injuries'])) {
            $injuries = $this->normalizeInjuries($fullMatchData['injuries'], $match->home_team_id);
            $advancedData['sofascore_data'] = array_merge(
                $advancedData['sofascore_data'] ?? [],
                ['injuries' => $injuries]
            );
        }

        // Stats équipe → forme récente
        if (!empty($fullMatchData['homeStats']) || !empty($fullMatchData['awayStats'])) {
            $advancedData['sofascore_data'] = array_merge(
                $advancedData['sofascore_data'] ?? [],
                ['recentForm' => $this->normalizeForm($fullMatchData['homeStats'], $fullMatchData['awayStats'])]
            );
        }

        // Prédictions API-Football → données de contexte
        if (!empty($fullMatchData['predictions'])) {
            $advancedData['context_data'] = $this->normalizeContextFromPredictions($fullMatchData['predictions']);
        }

        // Lineups → données tactiques
        if (!empty($fullMatchData['lineups'])) {
            $advancedData['tactical_data'] = $this->normalizeLineups($fullMatchData['lineups'], $match->home_team_id);
        }

        // Classement → données FBRef
        if (!empty($fullMatchData['standings'])) {
            $advancedData['fbref_data'] = $this->normalizeStandings(
                $fullMatchData['standings'],
                $match->home_team_id,
                $match->away_team_id
            );
        }

        if (!empty($advancedData)) {
            AdvancedData::updateOrCreate(
                ['match_id' => $match->id],
                $advancedData
            );

            $match->update(['enriched_at' => now()]);

            Log::info("MatchEnricher: données avancées enrichies pour match #{$match->id}", [
                'match' => $match->full_name,
                'dimensions' => array_keys($advancedData),
            ]);
        }

        return $match;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // NORMALISATEURS — API-Football → format Layer 2
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Normaliser les données H2H.
     */
    private function normalizeH2H(array $h2hFixtures): array
    {
        $matches = [];
        foreach (array_slice($h2hFixtures, 0, 10) as $fixture) {
            $matches[] = [
                'date' => $fixture['fixture']['date'] ?? null,
                'home' => $fixture['teams']['home']['name'] ?? null,
                'away' => $fixture['teams']['away']['name'] ?? null,
                'homeGoals' => $fixture['goals']['home'] ?? null,
                'awayGoals' => $fixture['goals']['away'] ?? null,
                'winner' => $fixture['teams']['home']['winner'] ? 'home' : ($fixture['teams']['away']['winner'] ? 'away' : 'draw'),
            ];
        }

        return $matches;
    }

    /**
     * Normaliser les données de blessures.
     */
    private function normalizeInjuries(array $injuries, ?int $homeTeamId): array
    {
        $home = [];
        $away = [];

        foreach ($injuries as $injury) {
            $entry = [
                'player' => $injury['player']['name'] ?? 'Inconnu',
                'type' => $injury['player']['type'] ?? 'Missing',
                'reason' => $injury['player']['reason'] ?? null,
            ];

            if (($injury['team']['id'] ?? null) === $homeTeamId) {
                $home[] = $entry;
            } else {
                $away[] = $entry;
            }
        }

        return ['home' => $home, 'away' => $away];
    }

    /**
     * Normaliser les données de forme depuis les stats d'équipe.
     */
    private function normalizeForm(mixed $homeStats, mixed $awayStats): array
    {
        $extractForm = function (mixed $stats): array {
            if (!$stats || !is_array($stats)) {
                return [];
            }

            // API-Football retourne la forme dans 'form' (ex: "WWDLW")
            $formString = $stats['form'] ?? '';
            $results = [];
            foreach (str_split($formString) as $char) {
                $results[] = match (strtoupper($char)) {
                    'W' => 'W',
                    'D' => 'D',
                    'L' => 'L',
                    default => null,
                };
            }

            return array_filter($results);
        };

        return [
            'home' => $extractForm($homeStats),
            'away' => $extractForm($awayStats),
        ];
    }

    /**
     * Normaliser le contexte depuis les prédictions API-Football.
     */
    private function normalizeContextFromPredictions(array $predictions): array
    {
        $advice = $predictions['predictions']['advice'] ?? '';
        $comparison = $predictions['comparison'] ?? [];

        // Détecter l'importance du match via le texte du conseil
        $importance = 'medium';
        if (str_contains(strtolower($advice), 'combo') || str_contains(strtolower($advice), 'draw')) {
            $importance = 'high';
        }

        return [
            'importance' => $importance,
            'reason' => $advice,
            'apiFootballAdvice' => $advice,
            'comparison' => $comparison,
        ];
    }

    /**
     * Normaliser les compositions (lineups) → données tactiques.
     *
     * Formation seule, null si absente. Le style était déduit de
     * FormationProfiles, supprimée à l'étape 1 : l'appel levait une exception
     * dès qu'une composition était publiée, et les cotes du match n'étaient
     * plus récupérées.
     */
    private function normalizeLineups(array $lineups, ?int $homeTeamId): array
    {
        $home = ['formation' => null];
        $away = ['formation' => null];

        foreach ($lineups as $lineup) {
            $teamId = $lineup['team']['id'] ?? null;
            $data = ['formation' => $lineup['formation'] ?? null];

            if ($teamId === $homeTeamId) {
                $home = $data;
            } else {
                $away = $data;
            }
        }

        return ['home' => $home, 'away' => $away];
    }

    /**
     * Normaliser le classement → données FBRef.
     */
    private function normalizeStandings(array $standings, ?int $homeTeamId, ?int $awayTeamId): array
    {
        $homeStanding = null;
        $awayStanding = null;

        foreach ($standings as $entry) {
            $teamId = $entry['team']['id'] ?? null;

            $data = [
                'rank' => $entry['rank'] ?? null,
                'points' => $entry['points'] ?? null,
                'played' => $entry['all']['played'] ?? null,
                'win' => $entry['all']['win'] ?? null,
                'draw' => $entry['all']['draw'] ?? null,
                'lose' => $entry['all']['lose'] ?? null,
                'goalsFor' => $entry['all']['goals']['for'] ?? null,
                'goalsAgainst' => $entry['all']['goals']['against'] ?? null,
                'form' => $entry['form'] ?? null,
            ];

            if ($teamId === $homeTeamId) {
                $homeStanding = $data;
            } elseif ($teamId === $awayTeamId) {
                $awayStanding = $data;
            }
        }

        return [
            'league' => [
                'home' => $homeStanding,
                'away' => $awayStanding,
            ],
        ];
    }
}
