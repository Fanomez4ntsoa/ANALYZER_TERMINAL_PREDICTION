<?php

namespace App\Jobs;

use App\Services\Api\ApiFootballService;
use App\Services\DataPipeline\MatchEnricherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchMatchDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 300;

    private ?string $date;
    private ?array $leagueIds;
    private bool $sync;
    private bool $allHours;

    /**
     * @param string|null $date       Date cible (YYYY-MM-DD). Null = aujourd'hui.
     * @param array|null  $leagueIds  Ligues à récupérer. Null = toutes les ligues configurées.
     * @param bool        $sync       Si true, FetchOddsJob sera aussi exécuté en synchrone.
     * @param bool        $allHours   Si true, ne pas filtrer par créneau horaire.
     */
    public function __construct(?string $date = null, ?array $leagueIds = null, bool $sync = false, bool $allHours = false)
    {
        $this->date = $date;
        $this->leagueIds = $leagueIds;
        $this->sync = $sync;
        $this->allHours = $allHours;
    }

    public function handle(ApiFootballService $apiFootball, MatchEnricherService $enricher): void
    {
        $date = $this->date ?? now()->format('Y-m-d');
        $trackedLeagues = $this->leagueIds ?? config('api-football.leagues');

        // Exclure les ligues temporairement desactivees (debut de saison, etc.)
        $inactiveLeagues = config('api-football.inactive_leagues', []);
        $trackedLeagues = array_values(array_diff($trackedLeagues, $inactiveLeagues));

        Log::info("Pipeline: FetchMatchDataJob démarré", [
            'date' => $date,
            'leagues' => $trackedLeagues,
        ]);

        // 0. Mettre à jour les scores des matchs de J-1 (rattrapage automatique)
        $this->updatePreviousDayResults($apiFootball, $enricher, $date, $trackedLeagues);

        // 1. Récupérer tous les matchs de la date
        $fixtures = $apiFootball->getFixturesByDate($date);

        if (!$fixtures || empty($fixtures)) {
            Log::info("Pipeline: aucun match trouvé pour le {$date}");
            $this->dispatchOddsJob([], $date);
            return;
        }

        // 2. Filtrer les ligues suivies
        $trackedFixtures = array_filter($fixtures, function ($fixture) use ($trackedLeagues) {
            return in_array($fixture['league']['id'] ?? 0, $trackedLeagues);
        });

        if (empty($trackedFixtures)) {
            Log::info("Pipeline: aucun match des ligues suivies pour le {$date}", [
                'total_fixtures' => count($fixtures),
            ]);
            $this->dispatchOddsJob([], $date);
            return;
        }

        // 3. Filtrer par créneau horaire (sauf si --all)
        if (!$this->allHours) {
            $startHour = (int) env('PIPELINE_MATCH_START_HOUR', 0);
            $endHour = (int) env('PIPELINE_MATCH_END_HOUR', 23);

            if ($startHour > 0 || $endHour < 23) {
                $beforeFilter = count($trackedFixtures);
                $trackedFixtures = array_filter($trackedFixtures, function ($fixture) use ($startHour, $endHour) {
                    $matchTime = $fixture['fixture']['date'] ?? '';
                    if (empty($matchTime)) return true;
                    $hour = (int) \Carbon\Carbon::parse($matchTime)->format('H');
                    return $hour >= $startHour && $hour <= $endHour;
                });

                Log::info("Pipeline: filtre horaire {$startHour}h-{$endHour}h UTC", [
                    'avant' => $beforeFilter,
                    'apres' => count($trackedFixtures),
                ]);
            }
        }

        if (empty($trackedFixtures)) {
            Log::info("Pipeline: aucun match dans le creneau horaire pour le {$date}");
            $this->dispatchOddsJob([], $date);
            return;
        }

        $count = count($trackedFixtures);
        Log::info("Pipeline: {$count} match(s) retenus", [
            'date' => $date,
        ]);

        // 3. Créer/mettre à jour chaque match en DB + enrichir avec données avancées
        $createdMatches = [];
        $leaguesWithMatches = [];

        foreach ($trackedFixtures as $fixtureData) {
            try {
                // Créer le match en DB
                $match = $enricher->upsertFromApiFootball($fixtureData);

                if (!$match) {
                    continue;
                }

                $createdMatches[] = $match;
                $leagueId = $fixtureData['league']['id'];
                $leaguesWithMatches[$leagueId] = true;

                // Enrichir avec données avancées (H2H, blessures, etc.)
                $fixtureId = $fixtureData['fixture']['id'];
                $fullData = $apiFootball->getFullMatchData($fixtureId);

                if ($fullData) {
                    $enricher->enrichWithAdvancedData($match, $fullData);
                }

                // Cotes API-Football (source PRINCIPALE) — 1 call = toutes lignes O/U + BTTS + DC
                $parsedOdds = $apiFootball->getFixtureOdds($fixtureId);
                if ($parsedOdds) {
                    $enricher->enrichWithApiFootballOdds($match, $parsedOdds);
                }

            } catch (\Exception $e) {
                Log::error("Pipeline: erreur enrichissement fixture", [
                    'fixture_id' => $fixtureData['fixture']['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Pipeline: FetchMatchDataJob terminé", [
            'date' => $date,
            'matches_created' => count($createdMatches),
            'leagues' => array_keys($leaguesWithMatches),
        ]);

        // 4. Chaîner vers FetchOddsJob avec les ligues qui ont des matchs
        $this->dispatchOddsJob(array_keys($leaguesWithMatches), $date);
    }

    /**
     * Dispatcher FetchOddsJob pour les ligues concernées.
     * Découplé pour que les cotes soient récupérées même si certaines fixtures échouent.
     */
    /**
     * Récupère les fixtures de J-1 et met à jour les scores des matchs FT.
     * Permet de rattraper automatiquement les résultats de la veille
     * sans dépendre d'une commande manuelle.
     */
    private function updatePreviousDayResults(
        ApiFootballService $apiFootball,
        MatchEnricherService $enricher,
        string $currentDate,
        array $trackedLeagues
    ): void {
        $previousDate = \Carbon\Carbon::parse($currentDate)->subDay()->format('Y-m-d');

        // Vérifier s'il y a des matchs J-1 incomplets en DB
        $incompleteCount = \App\Models\FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $previousDate)
            ->where('completed', false)
            ->count();

        if ($incompleteCount === 0) {
            return; // Rien à mettre à jour
        }

        Log::info("Pipeline: rattrapage J-1 ({$previousDate}) — {$incompleteCount} matchs incomplets");

        $previousFixtures = $apiFootball->getFixturesByDate($previousDate);
        if (!$previousFixtures) {
            return;
        }

        $updated = 0;
        foreach ($previousFixtures as $fixture) {
            $leagueId = $fixture['league']['id'] ?? 0;
            if (!in_array($leagueId, $trackedLeagues)) continue;

            $status = $fixture['fixture']['status']['short'] ?? '';
            if (!in_array($status, ['FT', 'AET', 'PEN'])) continue;

            $enricher->upsertFromApiFootball($fixture);
            $updated++;
        }

        Log::info("Pipeline: rattrapage J-1 termine — {$updated} matchs mis a jour avec scores FT");
    }

    private function dispatchOddsJob(array $leagueIds, string $date): void
    {
        if (empty($leagueIds)) {
            Log::info("Pipeline: pas de ligues avec matchs → FetchOddsJob non dispatché");
            return;
        }

        if ($this->sync) {
            FetchOddsJob::dispatchSync($leagueIds, $date);
        } else {
            FetchOddsJob::dispatch($leagueIds, $date);
        }

        Log::info("Pipeline: FetchOddsJob dispatché", [
            'leagues' => $leagueIds,
            'date' => $date,
            'sync' => $this->sync,
        ]);
    }

    /**
     * Gestion des échecs du job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Pipeline: FetchMatchDataJob ÉCHOUÉ", [
            'date' => $this->date,
            'error' => $exception->getMessage(),
        ]);

        // Même en cas d'échec de FetchMatchData, on tente quand même les cotes
        // car les matchs existants en DB peuvent quand même être enrichis
        $trackedLeagues = $this->leagueIds ?? config('api-football.leagues');
        $date = $this->date ?? now()->format('Y-m-d');

        if ($this->sync) {
            FetchOddsJob::dispatchSync($trackedLeagues, $date);
        } else {
            FetchOddsJob::dispatch($trackedLeagues, $date);
        }
    }
}
