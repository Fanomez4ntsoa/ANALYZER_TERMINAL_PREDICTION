<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OddsApiService
{
    private string $baseUrl;
    private string $apiKey;
    private string $regions;
    private string $oddsFormat;
    private array $cacheTtl;
    private array $quota;
    private array $extraMarkets;
    private bool $fetchExtraMarkets;

    public function __construct()
    {
        $this->baseUrl = config('odds-api.base_url');
        $this->apiKey = config('odds-api.key');
        $this->regions = config('odds-api.regions');
        $this->oddsFormat = config('odds-api.odds_format');
        $this->cacheTtl = config('odds-api.cache_ttl');
        $this->quota = config('odds-api.quota');
        $this->extraMarkets = config('odds-api.extra_markets', []);
        $this->fetchExtraMarkets = (bool) config('odds-api.fetch_extra_markets', false);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ODDS — Cotes par ligue (appel groupé)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les cotes pour une ligue entière.
     * C'est l'appel principal — 1 requête = tous les matchs de la ligue.
     *
     * @param string $sportKey  Ex: 'soccer_france_ligue_one'
     * @param array  $markets   Ex: ['h2h', 'totals']
     */
    public function getOddsBySport(string $sportKey, array $markets = []): ?array
    {
        $markets = $markets ?: config('odds-api.default_markets');
        $marketsStr = implode(',', $markets);

        $cacheKey = "odds_{$sportKey}_{$marketsStr}_{$this->regions}";

        return $this->cachedRequest($cacheKey, 'odds', "/sports/{$sportKey}/odds", [
            'regions' => $this->regions,
            'markets' => $marketsStr,
            'oddsFormat' => $this->oddsFormat,
        ]);
    }

    /**
     * Récupérer les cotes par league ID API-Football.
     * Fait le mapping automatiquement.
     */
    public function getOddsByLeagueId(int $leagueId, array $markets = []): ?array
    {
        $sportKey = $this->getSportKeyForLeague($leagueId);

        if (!$sportKey) {
            Log::warning("OddsApi: pas de mapping pour la ligue API-Football #{$leagueId}");
            return null;
        }

        return $this->getOddsBySport($sportKey, $markets);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ODDS PAR MATCH — Cotes d'un événement
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les cotes d'un seul événement par son event ID The Odds API.
     */
    public function getOddsByEventId(string $sportKey, string $eventId, array $markets = []): ?array
    {
        $markets = $markets ?: config('odds-api.default_markets');
        $marketsStr = implode(',', $markets);

        $cacheKey = "odds_event_{$eventId}_{$marketsStr}";

        return $this->cachedRequest($cacheKey, 'odds', "/sports/{$sportKey}/events/{$eventId}/odds", [
            'regions' => $this->regions,
            'markets' => $marketsStr,
            'oddsFormat' => $this->oddsFormat,
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // EVENTS — Liste des matchs (GRATUIT)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer la liste des événements à venir pour un sport.
     * Cet endpoint est GRATUIT (0 crédit).
     */
    public function getEvents(string $sportKey): ?array
    {
        $cacheKey = "events_{$sportKey}";

        return $this->cachedRequest($cacheKey, 'events', "/sports/{$sportKey}/events", [], false);
    }

    /**
     * Récupérer les événements par league ID API-Football.
     */
    public function getEventsByLeagueId(int $leagueId): ?array
    {
        $sportKey = $this->getSportKeyForLeague($leagueId);

        if (!$sportKey) {
            return null;
        }

        return $this->getEvents($sportKey);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SCORES — Résultats (GRATUIT)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les scores/résultats récents.
     * Utile pour la validation automatique des paris.
     */
    public function getScores(string $sportKey, int $daysFrom = 3): ?array
    {
        $cacheKey = "scores_{$sportKey}_{$daysFrom}";

        return $this->cachedRequest($cacheKey, 'events', "/sports/{$sportKey}/scores", [
            'daysFrom' => $daysFrom,
        ], false);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SPORTS — Liste des sports (GRATUIT)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer la liste de tous les sports disponibles.
     */
    public function getSports(bool $all = false): ?array
    {
        $cacheKey = 'sports_list' . ($all ? '_all' : '');
        $params = $all ? ['all' => 'true'] : [];

        return $this->cachedRequest($cacheKey, 'sports', '/sports', $params, false);
    }

    /**
     * Récupérer uniquement les sports soccer disponibles.
     */
    public function getSoccerSports(): ?array
    {
        $sports = $this->getSports(true);

        if (!$sports) {
            return null;
        }

        return array_values(array_filter($sports, function ($sport) {
            return str_starts_with($sport['key'] ?? '', 'soccer_');
        }));
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MÉTHODE COMBINÉE — Cotes pour toutes les ligues suivies
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupérer les cotes pour toutes les ligues configurées.
     * 1 requête par ligue — optimise le quota.
     *
     * @return array Tableau indexé par sport_key
     */
    public function getAllTrackedOdds(array $markets = []): array
    {
        $mapping = config('odds-api.league_mapping');
        $results = [];

        foreach ($mapping as $leagueId => $sportKey) {
            // Vérifier le quota avant chaque appel
            if ($this->isQuotaExhausted()) {
                Log::error('OddsApi: quota mensuel épuisé, arrêt des requêtes');
                break;
            }

            $odds = $this->getOddsBySport($sportKey, $markets);

            if ($odds) {
                $results[$sportKey] = $odds;
            }
        }

        return $results;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MATCHING — Relier un match API-Football aux cotes
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Trouver les cotes correspondant à un match API-Football.
     * Matching par noms d'équipes + date de début.
     *
     * @param string $homeTeam  Nom équipe domicile (API-Football)
     * @param string $awayTeam  Nom équipe extérieur (API-Football)
     * @param string $matchDate Date ISO du match
     * @param int    $leagueId  League ID API-Football
     */
    public function findOddsForMatch(string $homeTeam, string $awayTeam, string $matchDate, int $leagueId): ?array
    {
        $odds = $this->getOddsByLeagueId($leagueId);

        if (!$odds) {
            return null;
        }

        $normalized = $this->findEventOdds($odds, $homeTeam, $awayTeam, $matchDate);

        // Enrichir avec les marchés extras (alt totals, BTTS, DC) via l'endpoint per-event
        if ($normalized && $this->fetchExtraMarkets && !empty($this->extraMarkets) && !empty($normalized['event_id'])) {
            $sportKey = $this->getSportKeyForLeague($leagueId);
            if ($sportKey) {
                $extra = $this->getOddsByEventId($sportKey, $normalized['event_id'], $this->extraMarkets);
                if ($extra && is_array($extra)) {
                    $normalized = $this->mergeExtraMarkets($normalized, $extra);
                }
            }
        }

        return $normalized;
    }

    /**
     * Cotes 1X2 et totals d'une ligue, SANS cache : pour la cote de clôture, une
     * réponse en cache (jusqu'à 2 h) serait périmée. Coût : 2 crédits par appel
     * (h2h + totals, une région). Pas de marchés extras.
     */
    public function getFreshOddsByLeagueId(int $leagueId): ?array
    {
        $sportKey = $this->getSportKeyForLeague($leagueId);

        if (!$sportKey) {
            Log::warning("OddsApi: pas de mapping pour la ligue API-Football #{$leagueId}");
            return null;
        }

        return $this->request("/sports/{$sportKey}/odds", [
            'regions' => $this->regions,
            'markets' => implode(',', config('odds-api.default_markets')),
            'oddsFormat' => $this->oddsFormat,
        ]);
    }

    /**
     * Retrouver un match API-Football dans une liste d'événements The Odds API
     * (même date, noms d'équipe proches) et normaliser ses cotes.
     */
    public function findEventOdds(array $events, string $homeTeam, string $awayTeam, string $matchDate): ?array
    {
        $matchDate = substr($matchDate, 0, 10); // YYYY-MM-DD

        foreach ($events as $event) {
            $eventDate = substr($event['commence_time'] ?? '', 0, 10);

            if ($eventDate !== $matchDate) {
                continue;
            }

            // Matching flou sur les noms d'équipe
            $eventHome = $event['home_team'] ?? '';
            $eventAway = $event['away_team'] ?? '';

            if ($this->teamsMatch($homeTeam, $eventHome) && $this->teamsMatch($awayTeam, $eventAway)) {
                return $this->normalizeOdds($event);
            }
        }

        return null;
    }

    /**
     * Fusionner les marchés extras (BTTS, DC, alt_totals) issus de l'endpoint per-event
     * dans le résultat déjà normalisé du featured-markets endpoint.
     *
     * The Odds API peut renvoyer pour cet endpoint :
     *  - soit un objet event { bookmakers: [...] }
     *  - soit un tableau d'events (rare ici car on cible un eventId).
     */
    private function mergeExtraMarkets(array $normalized, array $eventOrList): array
    {
        // Normaliser : on ne traite qu'un seul event
        $event = isset($eventOrList['bookmakers']) ? $eventOrList : ($eventOrList[0] ?? null);
        if (!$event) {
            return $normalized;
        }

        $extraNormalized = $this->normalizeOdds($event);

        // Champs à reporter depuis les extras (sans écraser ce qui existe déjà)
        $extraFields = [
            'odds_over_2_0', 'odds_under_2_0',
            'odds_over_2_25', 'odds_under_2_25',
            'odds_btts_yes', 'odds_btts_no',
            'odds_dc_1x', 'odds_dc_12', 'odds_dc_x2',
        ];

        foreach ($extraFields as $field) {
            $extraValue = $extraNormalized[$field] ?? null;
            if ($extraValue !== null && ($normalized[$field] ?? null) === null) {
                $normalized[$field] = $extraValue;
            }
        }

        // Bookmaker count : on garde le max (les 2 endpoints peuvent diverger)
        $normalized['bookmaker_count'] = max(
            $normalized['bookmaker_count'] ?? 0,
            $extraNormalized['bookmaker_count'] ?? 0
        );

        return $normalized;
    }

    /**
     * Matching flou entre noms d'équipes (API-Football vs The Odds API).
     * Ex: "Paris Saint Germain" vs "Paris Saint-Germain" → match
     */
    private function teamsMatch(string $name1, string $name2): bool
    {
        $normalize = function (string $name): string {
            $name = mb_strtolower($name);
            $name = str_replace(['-', '.', "'", "\xe2\x80\x99"], ' ', $name);
            $name = preg_replace('/\s+/', ' ', trim($name));
            // Supprimer les suffixes courants
            $name = preg_replace('/\b(fc|cf|sc|ac|as|us|rc|og|ssc|afc|bsc)\b/', '', $name);
            return trim($name);
        };

        $n1 = $normalize($name1);
        $n2 = $normalize($name2);

        // Match exact après normalisation
        if ($n1 === $n2) {
            return true;
        }

        // L'un contient l'autre
        if (str_contains($n1, $n2) || str_contains($n2, $n1)) {
            return true;
        }

        // Calcul de similarité
        similar_text($n1, $n2, $percent);
        return $percent >= 70;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // NORMALISATION — Format unifié pour FootballMatch
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Normaliser les cotes d'un événement vers le format FootballMatch.
     * Ne retient que les cotes du bookmaker configuré (odds-api.bookmaker).
     * Si ce bookmaker est absent de la réponse, toutes les cotes restent à null
     * et ne seront donc pas stockées (aucun repli sur un autre bookmaker).
     */
    private function normalizeOdds(array $event): array
    {
        $bookmakers = $event['bookmakers'] ?? [];
        $selectedBookmaker = (string) config('odds-api.bookmaker', 'bet365');
        $result = [
            'event_id' => $event['id'],
            'home_team' => $event['home_team'],
            'away_team' => $event['away_team'],
            'commence_time' => $event['commence_time'],
            'bookmaker_count' => count($bookmakers),
            'bookmaker' => $selectedBookmaker,
            // Cotes 1X2 (bookmaker configuré uniquement)
            'odds_home' => null,
            'odds_draw' => null,
            'odds_away' => null,
            // Over/Under multi-lignes
            'odds_over_2_0' => null,
            'odds_under_2_0' => null,
            'odds_over_2_25' => null,
            'odds_under_2_25' => null,
            'odds_over_2_5' => null,
            'odds_under_2_5' => null,
            // BTTS
            'odds_btts_yes' => null,
            'odds_btts_no' => null,
            // Double Chance
            'odds_dc_1x' => null,
            'odds_dc_12' => null,
            'odds_dc_x2' => null,
            // Détail par bookmaker (pour comparaison)
            'bookmakers_detail' => [],
        ];

        // Mapping point → suffixe colonne pour les totals
        $totalsLines = [
            '2.0' => '2_0',
            '2.25' => '2_25',
            '2.5' => '2_5',
        ];

        foreach ($bookmakers as $bookmaker) {
            $bookmakerKey = $bookmaker['key'];
            $bookmakerOdds = [];

            foreach ($bookmaker['markets'] ?? [] as $market) {
                $marketKey = $market['key'];
                $outcomes = $market['outcomes'] ?? [];

                if ($marketKey === 'h2h') {
                    foreach ($outcomes as $outcome) {
                        $name = $outcome['name'];
                        $price = (float) $outcome['price'];

                        if ($name === $event['home_team']) {
                            $bookmakerOdds['home'] = $price;
                        } elseif ($name === 'Draw') {
                            $bookmakerOdds['draw'] = $price;
                        } else {
                            $bookmakerOdds['away'] = $price;
                        }
                    }
                    continue;
                }

                if ($marketKey === 'totals') {
                    foreach ($outcomes as $outcome) {
                        $point = isset($outcome['point']) ? (string) (float) $outcome['point'] : null;
                        $price = (float) $outcome['price'];

                        if ($point === null || !isset($totalsLines[$point])) {
                            continue;
                        }

                        $suffix = $totalsLines[$point];
                        $side = strtolower($outcome['name'] ?? '') === 'over' ? 'over' : 'under';
                        $bookKey = "{$side}_{$suffix}";
                        $resultKey = "odds_{$bookKey}";

                        $bookmakerOdds[$bookKey] = $price;
                    }
                    continue;
                }

                if ($marketKey === 'btts') {
                    foreach ($outcomes as $outcome) {
                        $name = strtolower($outcome['name'] ?? '');
                        $price = (float) $outcome['price'];

                        if ($name === 'yes') {
                            $bookmakerOdds['btts_yes'] = $price;
                        } elseif ($name === 'no') {
                            $bookmakerOdds['btts_no'] = $price;
                        }
                    }
                    continue;
                }

                if ($marketKey === 'double_chance') {
                    foreach ($outcomes as $outcome) {
                        $name = $outcome['name'] ?? '';
                        $price = (float) $outcome['price'];
                        $key = $this->mapDoubleChanceOutcome($name, $event['home_team'], $event['away_team']);

                        if ($key === null) {
                            continue;
                        }

                        $bookKey = "dc_{$key}";
                        $resultKey = "odds_{$bookKey}";

                        $bookmakerOdds[$bookKey] = $price;
                    }
                    continue;
                }
            }

            if (!empty($bookmakerOdds)) {
                $result['bookmakers_detail'][$bookmakerKey] = $bookmakerOdds;
            }
        }

        // Cotes retenues : uniquement celles du bookmaker configuré
        $selectedOdds = $result['bookmakers_detail'][$selectedBookmaker] ?? null;
        if ($selectedOdds === null) {
            Log::info("OddsApi: bookmaker '{$selectedBookmaker}' absent pour {$event['home_team']} vs {$event['away_team']} — aucune cote retenue");
        } else {
            foreach ($selectedOdds as $key => $price) {
                $result["odds_{$key}"] = $price;
            }
        }

        // Cotes moyennes tous bookmakers (information uniquement)
        $result['odds_avg'] = $this->calculateAverageOdds($result['bookmakers_detail']);

        return $result;
    }

    /**
     * Mapper un outcome Double Chance vers la clé interne (1x, 12, x2).
     *
     * The Odds API renvoie les outcomes sous différentes formes :
     * - "Home/Draw", "Draw/Away", "Home/Away"
     * - ou directement les noms d'équipes "{home} or Draw", "{home} or {away}", etc.
     */
    private function mapDoubleChanceOutcome(string $name, string $home, string $away): ?string
    {
        $normalized = strtolower(trim($name));
        $homeLower = strtolower($home);
        $awayLower = strtolower($away);

        $hasHome = $normalized === 'home' || str_contains($normalized, $homeLower);
        $hasAway = $normalized === 'away' || str_contains($normalized, $awayLower);
        $hasDraw = str_contains($normalized, 'draw');

        if ($hasHome && $hasDraw) {
            return '1x';
        }
        if ($hasAway && $hasDraw) {
            return 'x2';
        }
        if ($hasHome && $hasAway) {
            return '12';
        }

        return null;
    }

    /**
     * Calculer les cotes moyennes à travers tous les bookmakers.
     */
    private function calculateAverageOdds(array $bookmakersDetail): array
    {
        $keys = [
            'home', 'draw', 'away',
            'over_2_0', 'under_2_0',
            'over_2_25', 'under_2_25',
            'over_2_5', 'under_2_5',
            'btts_yes', 'btts_no',
            'dc_1x', 'dc_12', 'dc_x2',
        ];

        $sums = array_fill_keys($keys, 0.0);
        $counts = array_fill_keys($keys, 0);

        foreach ($bookmakersDetail as $odds) {
            foreach ($keys as $key) {
                if (isset($odds[$key])) {
                    $sums[$key] += $odds[$key];
                    $counts[$key]++;
                }
            }
        }

        $avg = [];
        foreach ($keys as $key) {
            $avg[$key] = $counts[$key] > 0 ? round($sums[$key] / $counts[$key], 3) : null;
        }

        return $avg;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // QUOTA — Compteur mensuel + alertes
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Obtenir le compteur de requêtes du mois en cours.
     */
    public function getMonthlyUsage(): array
    {
        $monthKey = $this->getMonthKey();

        return [
            'used' => (int) Cache::get("odds_api_quota_{$monthKey}", 0),
            'limit' => $this->quota['monthly_limit'],
            'alert_threshold' => $this->quota['alert_threshold'],
            'remaining' => $this->quota['monthly_limit'] - (int) Cache::get("odds_api_quota_{$monthKey}", 0),
            'month' => $monthKey,
        ];
    }

    /**
     * Vérifier si le quota mensuel est épuisé.
     */
    public function isQuotaExhausted(): bool
    {
        $used = (int) Cache::get("odds_api_quota_{$this->getMonthKey()}", 0);
        return $used >= $this->quota['monthly_limit'];
    }

    /**
     * Incrémenter le compteur de requêtes et vérifier le seuil d'alerte.
     */
    private function trackRequest(int $cost = 1): void
    {
        $monthKey = $this->getMonthKey();
        $cacheKey = "odds_api_quota_{$monthKey}";

        // Incrémenter (TTL = fin du mois)
        $current = (int) Cache::get($cacheKey, 0);
        $new = $current + $cost;

        // Cache jusqu'au 1er du mois prochain
        $endOfMonth = now()->endOfMonth()->addDay();
        Cache::put($cacheKey, $new, $endOfMonth);

        // Log chaque requête
        Log::info("OddsApi: requête #{$new}/{$this->quota['monthly_limit']} (coût: {$cost})", [
            'month' => $monthKey,
        ]);

        // Alerte si seuil dépassé
        if ($new >= $this->quota['alert_threshold'] && $current < $this->quota['alert_threshold']) {
            Log::warning("OddsApi: ALERTE QUOTA — {$new}/{$this->quota['monthly_limit']} requêtes utilisées ce mois", [
                'month' => $monthKey,
                'remaining' => $this->quota['monthly_limit'] - $new,
            ]);
        }
    }

    private function getMonthKey(): string
    {
        return now()->format('Y-m');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MAPPING — Ligue API-Football → sport key
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Obtenir le sport key The Odds API pour une ligue API-Football.
     */
    public function getSportKeyForLeague(int $leagueId): ?string
    {
        return config('odds-api.league_mapping')[$leagueId] ?? null;
    }

    /**
     * Obtenir toutes les ligues configurées avec leur mapping.
     */
    public function getLeagueMapping(): array
    {
        return config('odds-api.league_mapping');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TRANSPORT HTTP
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Requête directe à l'API (sans cache).
     *
     * @param bool $countsAsQuota Si true, incrémente le compteur mensuel
     */
    private function request(string $endpoint, array $params = [], bool $countsAsQuota = true): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('OddsApi: ODDS_API_KEY non configurée');
            return null;
        }

        // Bloquer si quota épuisé (sauf endpoints gratuits)
        if ($countsAsQuota && $this->isQuotaExhausted()) {
            Log::error('OddsApi: quota mensuel épuisé, requête bloquée');
            return null;
        }

        $params['apiKey'] = $this->apiKey;

        try {
            $response = Http::timeout(15)
                ->get($this->baseUrl . $endpoint, $params);

            if ($response->status() === 429) {
                Log::error('OddsApi: rate limit atteint (HTTP 429)');
                return null;
            }

            if ($response->failed()) {
                Log::error("OddsApi: erreur HTTP {$response->status()}", [
                    'endpoint' => $endpoint,
                ]);
                return null;
            }

            // Tracker la requête si elle coûte du quota
            if ($countsAsQuota) {
                $cost = (int) ($response->header('x-requests-last') ?? 1);
                $this->trackRequest($cost);

                // Log les headers de quota
                Log::debug('OddsApi: quota headers', [
                    'used' => $response->header('x-requests-used'),
                    'remaining' => $response->header('x-requests-remaining'),
                    'last_cost' => $response->header('x-requests-last'),
                ]);
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('OddsApi: exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Requête avec cache Laravel.
     *
     * @param bool $countsAsQuota Si true, la requête réelle coûte du quota
     */
    private function cachedRequest(string $cacheKey, string $ttlKey, string $endpoint, array $params = [], bool $countsAsQuota = true): ?array
    {
        $fullCacheKey = "odds_api_{$cacheKey}";
        $ttlMinutes = $this->cacheTtl[$ttlKey] ?? 120;

        return Cache::remember($fullCacheKey, now()->addMinutes($ttlMinutes), function () use ($endpoint, $params, $countsAsQuota) {
            return $this->request($endpoint, $params, $countsAsQuota);
        });
    }

    /**
     * Invalider le cache pour une clé donnée.
     */
    public function clearCache(string $cacheKey): void
    {
        Cache::forget("odds_api_{$cacheKey}");
    }
}
