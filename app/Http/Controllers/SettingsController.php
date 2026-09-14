<?php

namespace App\Http\Controllers;

use App\Models\PipelineRun;
use App\Services\Api\ApiFootballException;
use App\Services\Api\ApiFootballService;
use App\Services\Api\OddsApiService;
use App\Services\DataPipeline\PipelineLog;
use App\Support\Terminal\SystemState;
use Illuminate\View\View;

/**
 * Réglages en lecture : clés configurées, quotas, bookmakers, derniers passages
 * du pipeline. Tout se change dans .env, rien ici.
 */
class SettingsController extends Controller
{
    public function index(OddsApiService $oddsApi, ApiFootballService $apiFootball): View
    {
        $states = [];

        $apiFootballUsage = null;
        try {
            $apiFootballUsage = $apiFootball->getDailyUsage();
        } catch (ApiFootballException $e) {
            PipelineLog::caught('Terminal : quota API-Football', $e);
            $states[] = new SystemState(SystemState::WARNING, 'API-Football', 'Quota du jour illisible', $e->getMessage());
        }

        $runs = collect();
        try {
            $runs = PipelineRun::query()->orderByDesc('started_at')->take(10)->get();
        } catch (\Throwable $e) {
            PipelineLog::caught('Terminal : passages du pipeline', $e);
        }

        return view('terminal.settings', [
            'keys' => [
                'API-Football' => ['configured' => !empty(config('api-football.key')), 'role' => 'Matchs, scores, cotes Bet365, prédictions, blessures'],
                'The Odds API' => ['configured' => !empty(config('odds-api.key')), 'role' => 'Relevés Pinnacle pour le CLV, uniquement'],
                'OpenWeatherMap' => ['configured' => !empty(config('services.openweathermap.key')), 'role' => 'Météo (stockée, ne nourrit pas le modèle)'],
            ],
            'oddsQuota' => $oddsApi->getMonthlyUsage(),
            'apiFootballUsage' => $apiFootballUsage,
            'runs' => $runs,
            'config' => [
                'schedule' => config('pipeline.schedule_time') . ' ' . config('pipeline.schedule_timezone'),
                'match_window' => config('pipeline.match_start_hour') . ' h – ' . config('pipeline.match_end_hour') . ' h UTC',
                'odds_leagues' => implode(', ', config('api-football.odds_leagues', [])),
                'closing_leagues' => implode(', ', config('pipeline.closing.leagues', [])),
                'bookmaker_api_football' => config('api-football.preferred_bookmaker'),
                'bookmaker_clv' => config('odds-api.clv_bookmaker'),
                'reference_run' => config('football-data.reference_run'),
            ],
            'states' => $states,
        ]);
    }
}
