<?php

namespace App\Jobs;

use App\Models\FootballMatch;
use App\Services\Api\OddsApiService;
use App\Services\DataPipeline\MatchEnricherService;
use App\Services\DataPipeline\PipelineLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchOddsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 60;
    public int $timeout = 120;

    private array $leagueIds;
    private string $date;

    /**
     * @param array  $leagueIds Ligues API-Football à récupérer
     * @param string $date      Date cible (YYYY-MM-DD)
     */
    public function __construct(array $leagueIds, string $date)
    {
        $this->leagueIds = $leagueIds;
        $this->date = $date;
    }

    public function handle(OddsApiService $oddsApi, MatchEnricherService $enricher): void
    {
        Log::info("Pipeline: FetchOddsJob démarré", [
            'leagues' => $this->leagueIds,
            'date' => $this->date,
        ]);

        // Vérifier le quota avant de commencer
        $usage = $oddsApi->getMonthlyUsage();
        if ($oddsApi->isQuotaExhausted()) {
            Log::error("Pipeline: FetchOddsJob annulé — quota mensuel épuisé", [
                'used' => $usage['used'],
                'limit' => $usage['limit'],
            ]);
            return;
        }

        Log::info("Pipeline: quota Odds API", [
            'used' => $usage['used'],
            'remaining' => $usage['remaining'],
        ]);

        // Matchs de la date pas encore liés à un événement The Odds API.
        // Ce job ne stocke que odds_api_event_id (CLV) : il n'écrit aucune cote dans matches.
        $matches = FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $this->date)
            ->where('match_date', '>', now())
            ->whereIn('league_id', $this->leagueIds)
            ->whereNull('odds_api_event_id')
            ->get();

        if ($matches->isEmpty()) {
            Log::info("Pipeline: aucun match à lier à The Odds API pour le {$this->date}");
            return;
        }

        // Grouper les matchs par ligue pour faire 1 appel API par ligue
        $matchesByLeague = $matches->groupBy('league_id');
        $enrichedCount = 0;
        $failedCount = 0;

        foreach ($matchesByLeague as $leagueId => $leagueMatches) {
            // Vérifier le quota avant chaque appel de ligue
            if ($oddsApi->isQuotaExhausted()) {
                Log::warning("Pipeline: quota épuisé en cours de traitement, arrêt");
                break;
            }

            try {
                // 1 seul appel API pour toute la ligue → retourne tous les matchs avec cotes
                $leagueOdds = $oddsApi->getOddsByLeagueId($leagueId);

                if (!$leagueOdds) {
                    Log::warning("Pipeline: pas de cotes pour la ligue #{$leagueId}");
                    continue;
                }

                $eventCount = count($leagueOdds);
                Log::info("Pipeline: {$eventCount} événements avec cotes pour ligue #{$leagueId}");

                // Matcher chaque match DB avec les cotes
                foreach ($leagueMatches as $match) {
                    try {
                        $odds = $oddsApi->findOddsForMatch(
                            $match->home_team,
                            $match->away_team,
                            $match->match_date->format('Y-m-d'),
                            $leagueId
                        );

                        if ($odds) {
                            $enricher->linkOddsApiEvent($match, $odds);
                            $enrichedCount++;
                        } else {
                            Log::debug("Pipeline: pas de cotes trouvées pour {$match->full_name}");
                            $failedCount++;
                        }

                    } catch (\Exception $e) {
                        PipelineLog::caught('FetchOddsJob liaison événement', $e, ['match_id' => $match->id, 'match' => $match->full_name]);
                        $failedCount++;
                    }
                }

            } catch (\Exception $e) {
                PipelineLog::caught('FetchOddsJob ligue', $e, ['league_id' => $leagueId]);
            }
        }

        Log::info("Pipeline: FetchOddsJob terminé", [
            'date' => $this->date,
            'linked' => $enrichedCount,
            'failed' => $failedCount,
            'total' => $matches->count(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Pipeline: FetchOddsJob ÉCHOUÉ", [
            'leagues' => $this->leagueIds,
            'date' => $this->date,
            'error' => $exception->getMessage(),
        ]);
    }
}
