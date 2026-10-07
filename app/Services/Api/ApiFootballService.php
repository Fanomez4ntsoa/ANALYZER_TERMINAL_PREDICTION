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
     * Matchs à venir d'une ligue, dans la fenêtre de l'offre gratuite : aujourd'hui
     * et demain (UTC), 2 requêtes en cache 1 h, partagées avec le pipeline.
     *
     * L'offre gratuite refuse /fixtures?league=&next= (« Free plans do not have
     * access to the Next parameter ») et /fixtures?league=&season= pour la saison
     * en cours (« try from 2022 to 2024 »), constaté le 30/09/2026 : seule la
     * requête par date, sans ligue ni saison, couvre les matchs à venir.
     */
    public function getUpcomingFixtures(int $leagueId): array
    {
        $upcoming = [];
        foreach ([now('UTC'), now('UTC')->addDay()] as $day) {
            foreach ($this->getFixturesByDate($day->format('Y-m-d')) as $fixture) {
                if ((int) ($fixture['league']['id'] ?? 0) === $leagueId
                    && ($fixture['fixture']['status']['short'] ?? null) === 'NS') {
                    $upcoming[] = $fixture;
                }
            }
        }

        return $upcoming;
    }

    /**
     * Matchs d'une date, toutes ligues (1 requête, en cache 1 h). Sans paramètre
     * league ni season : l'offre gratuite refuse la saison en cours dès qu'on la
     * précise, alors que la requête par date la renvoie. Fenêtre J-1 à J+1.
     *
     * Une seule page est lue (235 matchs tenaient sur une page le 07/10/2026) :
     * une réponse paginée lève une exception plutôt que de perdre des matchs
     * sans bruit. Elle n'est pas mise en cache.
     */
    public function getFixturesByDate(string $date): array
    {
        $ttlMinutes = $this->cacheTtl['fixtures'] ?? 60;

        return Cache::remember("api_football_fixtures_date_{$date}", now()->addMinutes($ttlMinutes), function () use ($date) {
            $payload = $this->request('/fixtures', ['date' => $date]);

            if ((int) ($payload['paging']['total'] ?? 1) > 1) {
                throw new ApiFootballException(ApiFootballException::API, "réponse paginée inattendue pour la date {$date} ({$payload['paging']['total']} pages)", '/fixtures');
            }

            return $payload['response'] ?? [];
        });
    }

    /**
     * Un match par son identifiant : 1 requête, sans cache (rattrapage des scores).
     *
     * Seule voie pour un match plus ancien que la veille : l'offre gratuite refuse
     * /fixtures?date= hors de J-1 à J+1 et le paramètre ids (plusieurs matchs par
     * appel). Mesuré le 26/09/2026 : id= répond encore à 153 jours.
     *
     * @return array|null  Null si l'API ne connaît pas ce match. Toute erreur
     *                     d'appel lève ApiFootballException.
     */
    public function getFixtureById(int $fixtureId): ?array
    {
        return $this->request('/fixtures', ['id' => $fixtureId])['response'][0] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // DONNÉES FACULTATIVES — Collectées pour un test futur
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //
    // Retirés le 14/09/2026 : headtohead (paramètre last), teams/statistics et
    // standings (saison en cours), tous refusés par l'offre gratuite ; le
    // rechargement /fixtures?id= avant le match (déjà dans /fixtures?date= ; il
    // ne sert plus qu'au rattrapage des scores) ; les compositions
    // (jamais publiées à l'heure du passage quotidien).

    /**
     * Blessures et suspensions d'un match.
     */
    public function getInjuries(int $fixtureId): array
    {
        return $this->cachedRequest(
            "injuries_{$fixtureId}",
            'injuries',
            '/injuries',
            ['fixture' => $fixtureId]
        );
    }

    /**
     * Prédictions API-Football d'un match (bloc comparison stocké, n'entre dans
     * aucun calcul en mode marché seul).
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

    /**
     * Données facultatives d'un match : 2 requêtes. Lève une exception au premier
     * échec, que l'appelant traite comme un échec facultatif.
     */
    public function getOptionalMatchData(int $fixtureId): array
    {
        return [
            'predictions' => $this->getPredictions($fixtureId),
            'injuries' => $this->getInjuries($fixtureId),
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ODDS — Cotes du bookmaker configuré, match par match
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //
    // Pas de /odds?date= : l'offre gratuite plafonne le paramètre page à 3 (limite
    // non documentée, constatée le 14/09/2026 : 13 pages ce jour-là, refus « plan »
    // dès la page 4). /odds?league=&season= est refusé pour la saison en cours.
    // /odds?fixture= tient en une page : seule voie qui couvre tous les matchs.

    /**
     * Cotes du bookmaker configuré (api-football.preferred_bookmaker, Bet365) pour
     * un match : 1 requête, sans cache (odds_fetched_at doit dater la cote).
     *
     * @return array|null  Cotes normalisées, null si le bookmaker ne cote pas ce
     *                     match. Toute erreur d'appel lève ApiFootballException.
     */
    public function getFixtureOdds(int $fixtureId): ?array
    {
        $bookmaker = (int) config('api-football.preferred_bookmaker', 8);
        $payload = $this->request('/odds', ['fixture' => $fixtureId, 'bookmaker' => $bookmaker]);

        if ((int) ($payload['paging']['total'] ?? 1) > 1) {
            throw new ApiFootballException(ApiFootballException::API, "réponse paginée inattendue pour la fixture {$fixtureId}", '/odds');
        }

        foreach ($payload['response'] ?? [] as $event) {
            if ((int) ($event['fixture']['id'] ?? 0) === $fixtureId) {
                return $this->parseEventOdds($event, $bookmaker);
            }
        }

        return null;
    }

    /**
     * Normaliser un événement /odds en un dictionnaire de cotes.
     * Seul le bookmaker $bookmakerId est lu ; les autres sont ignorés.
     * Retourne null si ce bookmaker est absent de l'événement.
     */
    private function parseEventOdds(array $event, int $bookmakerId): ?array
    {
        $bookmakers = array_values(array_filter(
            $event['bookmakers'] ?? [],
            fn ($b) => (int) ($b['id'] ?? 0) === $bookmakerId
        ));

        if (empty($bookmakers)) {
            return null;
        }

        // Lignes Goals Over/Under cibles (mappées vers le suffixe colonne)
        $goalLines = [
            '1.5' => '1_5',
            '2.5' => '2_5',
            '3.5' => '3_5',
            '4.5' => '4_5',
        ];

        $result = [
            'fixture_id' => $event['fixture']['id'] ?? null,
            // Clé stable du bookmaker (« Bet365 » → « bet365 »), enregistrée avec les cotes
            'bookmaker' => strtolower(preg_replace('/\s+/', '', $bookmakers[0]['name'] ?? (string) $bookmakerId)),
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

        // Première valeur rencontrée pour le bookmaker retenu (pas de max multi-bookmakers)
        $apply = function (string $key, float $price) use (&$result) {
            if ($result[$key] === null) {
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
                        if ($val === 'home') $apply('odds_home', $price);
                        elseif ($val === 'draw') $apply('odds_draw', $price);
                        elseif ($val === 'away') $apply('odds_away', $price);
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
                        $apply("odds_{$side}_{$goalLines[$point]}", $price);
                    }
                    continue;
                }

                if ($betName === 'Both Teams Score') {
                    foreach ($values as $v) {
                        $price = (float) $v['odd'];
                        $val = strtolower($v['value']);
                        if ($val === 'yes') $apply('odds_btts_yes', $price);
                        elseif ($val === 'no') $apply('odds_btts_no', $price);
                    }
                    continue;
                }

                if ($betName === 'Double Chance') {
                    // Format API-Football : "Home/Draw" → 1X, "Home/Away" → 12, "Draw/Away" → X2
                    foreach ($values as $v) {
                        $price = (float) $v['odd'];
                        $val = strtolower($v['value']);
                        if ($val === 'home/draw') $apply('odds_dc_1x', $price);
                        elseif ($val === 'home/away') $apply('odds_dc_12', $price);
                        elseif ($val === 'draw/away') $apply('odds_dc_x2', $price);
                    }
                    continue;
                }
            }
        }

        return $result;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RECHERCHE — Trouver des équipes/ligues
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Rechercher une équipe par nom
     */
    public function searchTeam(string $name): ?array
    {
        return $this->request('/teams', ['search' => $name])['response'] ?? [];
    }

    /**
     * Rechercher une ligue par nom ou pays
     */
    public function searchLeague(string $name): ?array
    {
        return $this->request('/leagues', ['search' => $name])['response'] ?? [];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // QUOTA — Vérifier l'utilisation API
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Statut du compte (/status, non décompté du quota journalier).
     */
    public function getAccountStatus(): ?array
    {
        return $this->request('/status')['response'] ?? null;
    }

    /**
     * Requêtes du jour : consommées, limite, restantes.
     *
     * Les compteurs de l'API sont en retard : le 14/09/2026, juste après un passage
     * de 34 requêtes, /status en comptait 18 et l'en-tête de réponse 25 ; le
     * 30/09/2026, /status comptait 1 requête quand l'en-tête en décomptait 3. On
     * retient donc le plus pessimiste de trois sources : le corps de /status,
     * l'en-tête x-ratelimit-requests-remaining de cette même réponse (compteur du
     * compte, qui voit aussi les appels faits depuis une autre machine) et le
     * compteur local de ce serveur (jour UTC, remis à zéro à minuit UTC comme le
     * quota).
     *
     * @return array{current: int, limit: int, remaining: int, status_current: int, header_current: ?int, local_current: int}
     */
    public function getDailyUsage(): array
    {
        self::$dailyRemaining = null;
        $requests = $this->getAccountStatus()['requests'] ?? null;

        if (!isset($requests['current'], $requests['limit_day'])) {
            throw new ApiFootballException(ApiFootballException::API, 'quota journalier absent de /status', '/status');
        }

        self::$dailyLimit = (int) $requests['limit_day'];
        $headerCurrent = self::$dailyRemaining !== null ? max(0, self::$dailyLimit - self::$dailyRemaining) : null;
        $localCurrent = $this->localDailyCount();
        $current = max((int) $requests['current'], $headerCurrent ?? 0, $localCurrent);

        return [
            'current' => $current,
            'limit' => self::$dailyLimit,
            'remaining' => max(0, self::$dailyLimit - $current),
            'status_current' => (int) $requests['current'],
            'header_current' => $headerCurrent,
            'local_current' => $localCurrent,
        ];
    }

    /**
     * Requêtes restantes du jour, estimation pessimiste : minimum de l'en-tête du
     * dernier appel et de la limite moins le compteur local. Null si ni appel ni
     * /status dans ce processus.
     */
    public function lastKnownDailyRemaining(): ?int
    {
        $candidates = [];
        if (self::$dailyRemaining !== null) {
            $candidates[] = self::$dailyRemaining;
        }
        if (self::$dailyLimit !== null) {
            $candidates[] = max(0, self::$dailyLimit - $this->localDailyCount());
        }

        return $candidates ? min($candidates) : null;
    }

    /** Requêtes décomptées faites depuis ce serveur aujourd'hui (jour UTC). */
    private function localDailyCount(): int
    {
        return (int) Cache::get($this->localCountKey(), 0);
    }

    private function localCountKey(): string
    {
        return 'api_football_requests_' . now('UTC')->format('Y-m-d');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // TRANSPORT HTTP
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /** Horodatage du dernier appel réel, partagé par toutes les instances du processus. */
    private static float $lastRequestAt = 0.0;

    private static ?int $dailyRemaining = null;

    private static ?int $dailyLimit = null;

    /**
     * Requête directe à l'API (sans cache). Renvoie la réponse complète
     * (response, paging, results).
     *
     * Toute erreur lève ApiFootballException : limite de débit, quota journalier,
     * refus de l'offre, erreur HTTP ou réseau. Un appel qui échoue ne renvoie
     * jamais null, sinon l'appelant le confondrait avec une absence de donnée.
     *
     * Les appels sont espacés pour respecter api-football.rate_limit, et une
     * limite de débit atteinte malgré tout donne lieu à une seule nouvelle
     * tentative après une minute.
     */
    private function request(string $endpoint, array $params = [], bool $retryOnRateLimit = true): array
    {
        if (empty($this->apiKey)) {
            throw new ApiFootballException(ApiFootballException::CONFIG, 'API_FOOTBALL_KEY non configurée', $endpoint);
        }

        $this->throttle();

        try {
            $response = Http::withHeaders(['x-apisports-key' => $this->apiKey])
                ->timeout(20)
                ->get($this->baseUrl . $endpoint, $params);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new ApiFootballException(ApiFootballException::NETWORK, $e->getMessage(), $endpoint, $e);
        } finally {
            self::$lastRequestAt = microtime(true);
        }

        // Compteur local du jour UTC (/status n'est pas décompté du quota)
        if ($endpoint !== '/status') {
            $key = $this->localCountKey();
            Cache::add($key, 0, now('UTC')->endOfDay()->addHour());
            Cache::increment($key);
        }

        $remaining = $response->header('x-ratelimit-requests-remaining');
        if ($remaining !== '' && $remaining !== null) {
            self::$dailyRemaining = (int) $remaining;
        }

        $data = $response->json();
        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];

        $kind = match (true) {
            $response->status() === 429, isset($errors['rateLimit']) => ApiFootballException::RATE_LIMIT,
            isset($errors['requests']) => ApiFootballException::DAILY_QUOTA,
            isset($errors['plan']) => ApiFootballException::PLAN,
            $response->failed() => ApiFootballException::HTTP,
            !empty($errors) || !is_array($data) => ApiFootballException::API,
            default => null,
        };

        if ($kind === null) {
            return $data;
        }

        $message = !empty($errors) ? json_encode($errors, JSON_UNESCAPED_UNICODE) : "HTTP {$response->status()}";

        if ($kind === ApiFootballException::RATE_LIMIT && $retryOnRateLimit) {
            Log::channel('pipeline')->warning("ApiFootball: limite de débit atteinte sur {$endpoint}, nouvelle tentative dans 61 s", ['params' => $params]);
            sleep(61);

            return $this->request($endpoint, $params, false);
        }

        throw new ApiFootballException($kind, $message, $endpoint);
    }

    /**
     * Espacer les appels réels : 60 / requests_per_minute secondes, plus une marge.
     */
    private function throttle(): void
    {
        $perMinute = max(1, (int) config('api-football.rate_limit.requests_per_minute', 10));
        $interval = 60 / $perMinute + 0.5;
        $wait = self::$lastRequestAt + $interval - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
    }

    /**
     * Requête avec cache Laravel. Seule une réponse réussie est mise en cache ;
     * une exception n'est jamais mise en cache.
     */
    private function cachedRequest(string $cacheKey, string $ttlKey, string $endpoint, array $params = []): array
    {
        $fullCacheKey = "api_football_{$cacheKey}";
        $ttlMinutes = $this->cacheTtl[$ttlKey] ?? 60;

        return Cache::remember($fullCacheKey, now()->addMinutes($ttlMinutes), function () use ($endpoint, $params) {
            return $this->request($endpoint, $params)['response'] ?? [];
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
