<?php

namespace App\Jobs;

use App\Services\Api\ApiFootballException;
use App\Services\Api\ApiFootballService;
use App\Services\DataPipeline\MatchEnricherService;
use App\Services\DataPipeline\PipelineLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchMatchDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Une seule tentative : une relance automatique consommerait le budget du jour une seconde fois
    public int $tries = 1;
    public int $backoff = 30;
    // Appels espacés de 6,5 s (10/minute) : un passage complet dure plusieurs minutes
    public int $timeout = 1800;

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

    /**
     * Bilan du dernier passage dans ce processus, lu par pipeline:run-sync pour
     * son code de sortie (dispatchSync ne renvoie rien).
     */
    public static ?array $lastSummary = null;

    /**
     * Ordre imposé par le budget de l'offre gratuite (100 requêtes/jour) : les cotes
     * d'abord et seules (1 requête par match), puis les scores de la veille, puis le
     * facultatif (prédictions API-Football, blessures) tant que le budget le permet
     * et seulement si les cotes sont complètes. Une donnée facultative n'empêche
     * jamais une donnée indispensable.
     */
    public function handle(ApiFootballService $apiFootball, MatchEnricherService $enricher): void
    {
        $date = $this->date ?? now()->format('Y-m-d');
        $trackedLeagues = $this->leagueIds ?? config('api-football.leagues');
        $log = Log::channel('pipeline');

        // Exclure les ligues temporairement desactivees (debut de saison, etc.)
        $inactiveLeagues = config('api-football.inactive_leagues', []);
        $trackedLeagues = array_values(array_diff($trackedLeagues, $inactiveLeagues));

        $summary = [
            'date' => $date,
            'daily_remaining_start' => null,
            'matches' => 0,
            'kicked_off_score_only' => 0,
            'with_odds' => 0,
            'bookmaker_absent' => [],
            'odds_failed' => [],
            'odds_not_covered' => [],
            'previous_day' => null,
            'optional_done' => 0,
            'optional_skipped_budget' => 0,
            'optional_failures' => 0,
            'indispensable_complete' => false,
        ];
        self::$lastSummary = $summary;

        // Budget du jour (/status ne consomme pas de requête)
        $usage = $apiFootball->getDailyUsage();
        $summary['daily_remaining_start'] = $usage['remaining'];

        $log->info("Pipeline: FetchMatchDataJob démarré", [
            'date' => $date,
            'daily_usage' => $usage,
        ]);

        // 1. Matchs de la date (1 requête, en cache 1 h). Un échec ici arrête le job.
        $fixtures = $apiFootball->getFixturesByDate($date) ?? [];

        $trackedFixtures = array_filter($fixtures, fn ($fixture) => in_array($fixture['league']['id'] ?? 0, $trackedLeagues));

        // Filtrer par créneau horaire (sauf si --all)
        if (!$this->allHours) {
            $startHour = (int) config('pipeline.match_start_hour');
            $endHour = (int) config('pipeline.match_end_hour');

            if ($startHour > 0 || $endHour < 23) {
                $trackedFixtures = array_filter($trackedFixtures, function ($fixture) use ($startHour, $endHour) {
                    $matchTime = $fixture['fixture']['date'] ?? '';
                    if (empty($matchTime)) return true;
                    $hour = (int) \Carbon\Carbon::parse($matchTime)->format('H');
                    return $hour >= $startHour && $hour <= $endHour;
                });
            }
        }

        // 2. Créer/mettre à jour les matchs (aucune requête)
        $upcoming = [];
        foreach ($trackedFixtures as $fixtureData) {
            $match = $enricher->upsertFromApiFootball($fixtureData);
            if (!$match) {
                continue;
            }
            $summary['matches']++;

            // Match commencé, reporté ou terminé : score seul. Données avancées et
            // cotes écrites après le coup d'envoi contaminent le match (règle 5).
            if (!MatchEnricherService::isBeforeKickoff($fixtureData)) {
                $summary['kicked_off_score_only']++;
                continue;
            }

            $upcoming[(int) $fixtureData['fixture']['id']] = $match;
        }

        // 3. Cotes : indispensables, en premier et seules. 1 requête par match
        //    (/odds?date= inutilisable : l'offre gratuite plafonne page à 3).
        if (!empty($upcoming)) {
            $remaining = $apiFootball->lastKnownDailyRemaining() ?? $usage['remaining'];

            if ($remaining < count($upcoming)) {
                $log->warning("Pipeline: budget API-Football insuffisant pour coter les matchs du {$date} : " . count($upcoming) . " match(s), {$remaining} requête(s) restante(s). Cotes partielles, matchs non couverts listés en fin de job.");
            }

            $stop = null;
            foreach ($upcoming as $fixtureId => $match) {
                $remaining = $apiFootball->lastKnownDailyRemaining() ?? $usage['remaining'];
                if ($stop !== null || $remaining < 1) {
                    $summary['odds_not_covered'][] = $match->full_name;
                    continue;
                }

                try {
                    $odds = $apiFootball->getFixtureOdds($fixtureId);
                } catch (\Exception $e) {
                    $summary['odds_failed'][] = $match->full_name;
                    PipelineLog::caught('FetchMatchDataJob cotes', $e, ['match_id' => $match->id, 'fixture_id' => $fixtureId]);

                    // Débit persistant, quota ou refus de l'offre : les appels suivants
                    // échoueraient pareil, les matchs restants sont non couverts.
                    if ($e instanceof ApiFootballException && in_array($e->kind, [ApiFootballException::RATE_LIMIT, ApiFootballException::DAILY_QUOTA, ApiFootballException::PLAN], true)) {
                        $stop = $e->kind;
                    }
                    continue;
                }

                if ($odds === null) {
                    // Appel réussi : le bookmaker ne cote vraiment pas ce match
                    $summary['bookmaker_absent'][] = $match->full_name;
                    continue;
                }

                $enricher->enrichWithApiFootballOdds($match, $odds);
                $summary['with_odds']++;
            }

            if (!empty($summary['bookmaker_absent'])) {
                $log->warning("Pipeline: bookmaker " . config('api-football.preferred_bookmaker') . " absent sur " . count($summary['bookmaker_absent']) . " match(s)", [
                    'matches' => $summary['bookmaker_absent'],
                ]);
            }
        }

        $summary['indispensable_complete'] = empty($summary['odds_not_covered']) && empty($summary['odds_failed']);

        // 4. Scores de la veille (1 requête au plus)
        try {
            $summary['previous_day'] = $this->updatePreviousDayResults($apiFootball, $enricher, $date, $trackedLeagues);
        } catch (\Exception $e) {
            $summary['previous_day'] = 'échec';
            $summary['indispensable_complete'] = false;
            PipelineLog::caught('FetchMatchDataJob scores de la veille', $e, ['date' => $date]);
        }

        // 5. Facultatif : prédictions API-Football et blessures, 2 requêtes par match,
        //    tant que le budget reste au-dessus de la réserve. Jamais avant les cotes,
        //    et pas du tout si elles sont incomplètes : le budget reste à une relance.
        $reserve = (int) config('api-football.budget.optional_reserve', 10);
        $pending = $summary['indispensable_complete'] ? $upcoming : [];
        if (!$summary['indispensable_complete'] && !empty($upcoming)) {
            $summary['optional_skipped_budget'] = count($upcoming);
            $log->warning("Pipeline: données facultatives non collectées, cotes incomplètes : budget gardé pour une relance");
        }
        foreach ($pending as $fixtureId => $match) {
            $remaining = $apiFootball->lastKnownDailyRemaining() ?? $usage['remaining'];
            if ($remaining - 2 < $reserve) {
                $summary['optional_skipped_budget'] = count($pending);
                $log->warning("Pipeline: données facultatives abandonnées pour {$summary['optional_skipped_budget']} match(s) : {$remaining} requêtes restantes, réserve de {$reserve}");
                break;
            }

            try {
                $enricher->enrichWithAdvancedData($match, $apiFootball->getOptionalMatchData($fixtureId));
                $summary['optional_done']++;
            } catch (\Exception $e) {
                $summary['optional_failures']++;
                PipelineLog::caught('FetchMatchDataJob données facultatives', $e, ['match_id' => $match->id]);

                // Limite de débit persistante ou quota : on abandonne le facultatif
                if ($e instanceof ApiFootballException && in_array($e->kind, [ApiFootballException::RATE_LIMIT, ApiFootballException::DAILY_QUOTA], true)) {
                    $summary['optional_skipped_budget'] = count($pending) - 1;
                    $log->warning("Pipeline: données facultatives abandonnées ({$e->kind}) pour {$summary['optional_skipped_budget']} match(s) restant(s)");
                    break;
                }
            }
            unset($pending[$fixtureId]);
        }

        $summary['daily_remaining_end'] = $apiFootball->lastKnownDailyRemaining();
        self::$lastSummary = $summary;

        if (!$summary['indispensable_complete']) {
            $log->error("Pipeline: FetchMatchDataJob INCOMPLET — cotes ou scores manquants pour cause d'échec ou de budget", $summary);
        } elseif ($summary['optional_failures'] > 0 || $summary['optional_skipped_budget'] > 0) {
            $log->warning("Pipeline: FetchMatchDataJob terminé, cotes complètes, facultatif partiel", $summary);
        } else {
            $log->info("Pipeline: FetchMatchDataJob terminé", $summary);
        }

        // 6. Liaison des événements The Odds API (CLV)
        $leagues = array_values(array_unique(array_map(fn ($m) => $m->league_id, $upcoming)));
        $this->dispatchOddsJob($leagues, $date);
    }

    /** @return string[] */
    private function names(array $matches): array
    {
        return array_values(array_map(fn ($m) => $m->full_name, $matches));
    }

    /**
     * Récupère les fixtures de J-1 et met à jour les scores des matchs FT.
     * Permet de rattraper automatiquement les résultats de la veille
     * sans dépendre d'une commande manuelle.
     *
     * @return string Bilan court pour le journal
     */
    private function updatePreviousDayResults(
        ApiFootballService $apiFootball,
        MatchEnricherService $enricher,
        string $currentDate,
        array $trackedLeagues
    ): string {
        $previousDate = \Carbon\Carbon::parse($currentDate)->subDay()->format('Y-m-d');

        // Vérifier s'il y a des matchs J-1 incomplets en DB
        $incompleteCount = \App\Models\FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $previousDate)
            ->where('completed', false)
            ->count();

        if ($incompleteCount === 0) {
            return 'rien à mettre à jour';
        }

        $previousFixtures = $apiFootball->getFixturesByDate($previousDate) ?? [];

        $updated = 0;
        foreach ($previousFixtures as $fixture) {
            $leagueId = $fixture['league']['id'] ?? 0;
            if (!in_array($leagueId, $trackedLeagues)) continue;

            $status = $fixture['fixture']['status']['short'] ?? '';
            if (!in_array($status, ['FT', 'AET', 'PEN'])) continue;

            $enricher->upsertFromApiFootball($fixture);
            $updated++;
        }

        return "{$updated} score(s) FT sur {$incompleteCount} match(s) incomplet(s) du {$previousDate}";
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
     * Gestion des échecs du job. Plus de relance de FetchOddsJob : lier des
     * événements The Odds API sans matchs importés dépense des crédits pour rien.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('pipeline')->error("Pipeline: FetchMatchDataJob ÉCHOUÉ", [
            'date' => $this->date,
            'exception' => get_class($exception),
            'error' => $exception->getMessage(),
        ]);
    }
}
