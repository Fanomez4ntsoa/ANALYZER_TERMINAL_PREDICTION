<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiFootballService
{
    private string $baseUrl;
    private string $apiKey;
    private array $cacheTtl;

    public function __construct()
    {
        $this->baseUrl = config('api-football.base_url');
        $this->apiKey = config('api-football.key');
        $this->cacheTtl = config('api-football.cache_ttl');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // FIXTURES — Matchs à venir et passés
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les matchs à venir pour une ligue
     */
    public function getUpcomingFixtures(int $leagueId, ?int $season = null, int $next = 10): ?array
    {
        $season = $season ?? config('api-football.default_season');

        return $this->cachedRequest(
            "fixtures_upcoming_{$leagueId}_{$season}_{$next}",
            'fixtures',
            '/fixtures',
            [
                'league' => $leagueId,
                'season' => $season,
                'next' => $next,
            ]
        );
    }

    /**
     * Récupérer les matchs d'une date précise
     */
    public function getFixturesByDate(string $date, ?int $leagueId = null, ?int $season = null): ?array
    {
        $params = ['date' => $date];
        if ($leagueId) {
            $params['league'] = $leagueId;
            $params['season'] = $season ?? config('api-football.default_season');
        }

        $cacheKey = "fixtures_date_{$date}" . ($leagueId ? "_{$leagueId}" : '') . ($season ? "_{$season}" : '');

        return $this->cachedRequest($cacheKey, 'fixtures', '/fixtures', $params);
    }

    /**
     * Récupérer un match par son ID
     */
    public function getFixture(int $fixtureId): ?array
    {
        $result = $this->cachedRequest(
            "fixture_{$fixtureId}",
            'fixtures',
            '/fixtures',
            ['id' => $fixtureId]
        );

        return $result[0] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HEAD TO HEAD — Confrontations directes
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer l'historique H2H entre deux équipes
     *
     * @param int $teamId1 ID première équipe
     * @param int $teamId2 ID deuxième équipe
     * @param int $last Nombre de dernières confrontations
     */
    public function getHeadToHead(int $teamId1, int $teamId2, int $last = 10): ?array
    {
        $h2hKey = min($teamId1, $teamId2) . '-' . max($teamId1, $teamId2);

        return $this->cachedRequest(
            "h2h_{$h2hKey}_{$last}",
            'h2h',
            '/fixtures/headtohead',
            [
                'h2h' => "{$teamId1}-{$teamId2}",
                'last' => $last,
            ]
        );
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TEAM STATISTICS — Stats d'équipe (forme)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les statistiques complètes d'une équipe pour une saison
     */
    public function getTeamStatistics(int $teamId, int $leagueId, ?int $season = null): ?array
    {
        $season = $season ?? config('api-football.default_season');

        $result = $this->cachedRequest(
            "team_stats_{$teamId}_{$leagueId}_{$season}",
            'statistics',
            '/teams/statistics',
            [
                'team' => $teamId,
                'league' => $leagueId,
                'season' => $season,
            ]
        );

        return $result;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // INJURIES — Blessures et suspensions
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les blessures pour un match
     */
    public function getInjuries(int $fixtureId): ?array
    {
        return $this->cachedRequest(
            "injuries_{$fixtureId}",
            'injuries',
            '/injuries',
            ['fixture' => $fixtureId]
        );
    }

    /**
     * Récupérer les blessures d'une équipe (saison en cours)
     */
    public function getTeamInjuries(int $teamId, ?int $season = null): ?array
    {
        $season = $season ?? config('api-football.default_season');

        return $this->cachedRequest(
            "injuries_team_{$teamId}_{$season}",
            'injuries',
            '/injuries',
            [
                'team' => $teamId,
                'season' => $season,
            ]
        );
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PREDICTIONS — Prédictions API-Football
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les prédictions API-Football pour un match
     * Utilisable comme "Source D" dans le pipeline Layer 1
     */
    public function getPredictions(int $fixtureId): ?array
    {
        $result = $this->cachedRequest(
            "predictions_{$fixtureId}",
            'predictions',
            '/predictions',
            ['fixture' => $fixtureId]
        );

        return $result[0] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // LINEUPS — Compositions d'équipe
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les compositions pour un match (disponible ~1h avant)
     */
    public function getLineups(int $fixtureId): ?array
    {
        return $this->cachedRequest(
            "lineups_{$fixtureId}",
            'lineups',
            '/fixtures/lineups',
            ['fixture' => $fixtureId]
        );
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // STANDINGS — Classements
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer le classement d'une ligue
     */
    public function getStandings(int $leagueId, ?int $season = null): ?array
    {
        $season = $season ?? config('api-football.default_season');

        $result = $this->cachedRequest(
            "standings_{$leagueId}_{$season}",
            'standings',
            '/standings',
            [
                'league' => $leagueId,
                'season' => $season,
            ]
        );

        // L'API retourne un tableau imbriqué : [0]['league']['standings'][0]
        return $result[0]['league']['standings'][0] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // FIXTURE STATISTICS — Stats d'un match
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les statistiques détaillées d'un match joué
     */
    public function getFixtureStatistics(int $fixtureId): ?array
    {
        return $this->cachedRequest(
            "fixture_stats_{$fixtureId}",
            'statistics',
            '/fixtures/statistics',
            ['fixture' => $fixtureId]
        );
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ODDS — Cotes par fixture (toutes lignes O/U + BTTS + DC)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les cotes d'un match : 1 appel /odds → tous marchés / lignes / bookmakers.
     *
     * Stratégie : essayer le bookmaker préféré (Bet365 par défaut) d'abord pour limiter
     * le payload, fallback sur tous les bookmakers si le préféré ne couvre pas le match.
     *
     * @return array|null  Cotes normalisées (cf. parseFixtureOdds) ou null si pas dispo
     */
    public function getFixtureOdds(int $fixtureId): ?array
    {
        $preferred = (int) config('api-football.preferred_bookmaker', 8);

        // 1. Tentative ciblée sur le bookmaker préféré
        $payload = $this->cachedRequest(
            "fixture_odds_{$fixtureId}_bk{$preferred}",
            'odds',
            '/odds',
            ['fixture' => $fixtureId, 'bookmaker' => $preferred]
        );

        $parsed = $this->parseFixtureOdds($payload);

        // 2. Fallback : si le préféré n'a pas couvert (= aucune cote pivot 2.5),
        // on récupère tous les bookmakers et prend la meilleure cote par marché.
        if ($parsed === null || ($parsed['odds_over_2_5'] === null && $parsed['odds_under_2_5'] === null)) {
            Log::info("ApiFootball: bookmaker préféré ({$preferred}) sans cotes pour fixture {$fixtureId} — fallback all bookmakers");

            $payloadAll = $this->cachedRequest(
                "fixture_odds_{$fixtureId}_all",
                'odds',
                '/odds',
                ['fixture' => $fixtureId]
            );

            $parsedAll = $this->parseFixtureOdds($payloadAll);
            if ($parsedAll !== null) {
                return $parsedAll;
            }
        }

        return $parsed;
    }

    /**
     * Normaliser la réponse /odds en un dictionnaire de cotes.
     * Quand plusieurs bookmakers offrent une ligne, on garde la MEILLEURE cote.
     */
    private function parseFixtureOdds(?array $response): ?array
    {
        if (empty($response) || empty($response[0])) {
            return null;
        }

        $event = $response[0];
        $bookmakers = $event['bookmakers'] ?? [];

        // Lignes Goals Over/Under cibles (mappées vers le suffixe colonne)
        $goalLines = [
            '1.5' => '1_5',
            '2.5' => '2_5',
            '3.5' => '3_5',
            '4.5' => '4_5',
        ];

        $result = [
            'fixture_id' => $event['fixture']['id'] ?? null,
            'bookmaker_count' => count($bookmakers),
            'bookmakers_used' => array_map(fn($b) => $b['name'] ?? '?', $bookmakers),
            // 1X2
            'odds_home' => null,
            'odds_draw' => null,
            'odds_away' => null,
            // Over/Under (4 lignes)
            'odds_over_1_5' => null,  'odds_under_1_5' => null,
            'odds_over_2_5' => null,  'odds_under_2_5' => null,
            'odds_over_3_5' => null,  'odds_under_3_5' => null,
            'odds_over_4_5' => null,  'odds_under_4_5' => null,
            // BTTS
            'odds_btts_yes' => null, 'odds_btts_no' => null,
            // Double Chance
            'odds_dc_1x' => null, 'odds_dc_12' => null, 'odds_dc_x2' => null,
        ];

        $applyMax = function (string $key, float $price) use (&$result) {
            if ($result[$key] === null || $price > $result[$key]) {
                $result[$key] = $price;
            }
        };

        foreach ($bookmakers as $bk) {
            foreach ($bk['bets'] ?? [] as $bet) {
                $betName = $bet['name'] ?? '';
                $values = $bet['values'] ?? [];

                if ($betName === 'Match Winner') {
                    foreach ($values as $v) {
                        $price = (float) $v['odd'];
                        $val = strtolower($v['value']);
                        if ($val === 'home') $applyMax('odds_home', $price);
                        elseif ($val === 'draw') $applyMax('odds_draw', $price);
                        elseif ($val === 'away') $applyMax('odds_away', $price);
                    }
                    continue;
                }

                if ($betName === 'Goals Over/Under') {
                    foreach ($values as $v) {
                        $price = (float) $v['odd'];
                        // Format de v['value'] : "Over 2.5" / "Under 3.5"
                        if (!preg_match('/^(Over|Under)\s+([\d.]+)$/i', $v['value'], $m)) continue;
                        $side = strtolower($m[1]);  // over | under
                        $point = (string) (float) $m[2];  // normaliser "2.5" / "3.5"
                        if (!isset($goalLines[$point])) continue;
                        $applyMax("odds_{$side}_{$goalLines[$point]}", $price);
                    }
                    continue;
                }

                if ($betName === 'Both Teams Score') {
                    foreach ($values as $v) {
                        $price = (float) $v['odd'];
                        $val = strtolower($v['value']);
                        if ($val === 'yes') $applyMax('odds_btts_yes', $price);
                        elseif ($val === 'no') $applyMax('odds_btts_no', $price);
                    }
                    continue;
                }

                if ($betName === 'Double Chance') {
                    // Format API-Football : "Home/Draw" → 1X, "Home/Away" → 12, "Draw/Away" → X2
                    foreach ($values as $v) {
                        $price = (float) $v['odd'];
                        $val = strtolower($v['value']);
                        if ($val === 'home/draw') $applyMax('odds_dc_1x', $price);
                        elseif ($val === 'home/away') $applyMax('odds_dc_12', $price);
                        elseif ($val === 'draw/away') $applyMax('odds_dc_x2', $price);
                    }
                    continue;
                }
            }
        }

        return $result;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MÉTHODE COMBINÉE — Données complètes d'un match
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer toutes les données nécessaires pour analyser un match.
     * Combine : fixture, H2H, stats équipes, blessures, prédictions, classement.
     *
     * C'est cette méthode que le pipeline (MatchEnricherService) appellera.
     */
    public function getFullMatchData(int $fixtureId): ?array
    {
        $fixture = $this->getFixture($fixtureId);

        if (!$fixture) {
            Log::warning("ApiFootball: fixture {$fixtureId} introuvable");
            return null;
        }

        $homeTeamId = $fixture['teams']['home']['id'] ?? null;
        $awayTeamId = $fixture['teams']['away']['id'] ?? null;
        $leagueId = $fixture['league']['id'] ?? null;

        if (!$homeTeamId || !$awayTeamId || !$leagueId) {
            Log::warning("ApiFootball: données incomplètes pour fixture {$fixtureId}");
            return null;
        }

        return [
            'fixture' => $fixture,
            'h2h' => $this->getHeadToHead($homeTeamId, $awayTeamId),
            'homeStats' => $this->getTeamStatistics($homeTeamId, $leagueId),
            'awayStats' => $this->getTeamStatistics($awayTeamId, $leagueId),
            'injuries' => $this->getInjuries($fixtureId),
            'predictions' => $this->getPredictions($fixtureId),
            'lineups' => $this->getLineups($fixtureId),
            'standings' => $this->getStandings($leagueId),
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RECHERCHE — Trouver des équipes/ligues
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Rechercher une équipe par nom
     */
    public function searchTeam(string $name): ?array
    {
        return $this->request('/teams', ['search' => $name]);
    }

    /**
     * Rechercher une ligue par nom ou pays
     */
    public function searchLeague(string $name): ?array
    {
        return $this->request('/leagues', ['search' => $name]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // QUOTA — Vérifier l'utilisation API
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Vérifier le quota API restant
     */
    public function getAccountStatus(): ?array
    {
        return $this->request('/status');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TRANSPORT HTTP
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Requête directe à l'API (sans cache)
     */
    private function request(string $endpoint, array $params = []): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('ApiFootball: API_FOOTBALL_KEY non configurée');
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-apisports-key' => $this->apiKey,
            ])
                ->timeout(15)
                ->get($this->baseUrl . $endpoint, $params);

            if ($response->failed()) {
                Log::error("ApiFootball: erreur HTTP {$response->status()}", [
                    'endpoint' => $endpoint,
                    'params' => $params,
                ]);
                return null;
            }

            $data = $response->json();

            // Vérifier les erreurs API
            if (!empty($data['errors']) && count($data['errors']) > 0) {
                Log::error('ApiFootball: erreur API', [
                    'endpoint' => $endpoint,
                    'errors' => $data['errors'],
                ]);
                return null;
            }

            return $data['response'] ?? null;

        } catch (\Exception $e) {
            Log::error('ApiFootball: exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Requête avec cache Laravel
     */
    private function cachedRequest(string $cacheKey, string $ttlKey, string $endpoint, array $params = []): ?array
    {
        $fullCacheKey = "api_football_{$cacheKey}";
        $ttlMinutes = $this->cacheTtl[$ttlKey] ?? 60;

        return Cache::remember($fullCacheKey, now()->addMinutes($ttlMinutes), function () use ($endpoint, $params) {
            return $this->request($endpoint, $params);
        });
    }

    /**
     * Invalider le cache pour une clé donnée
     */
    public function clearCache(string $cacheKey): void
    {
        Cache::forget("api_football_{$cacheKey}");
    }
}
