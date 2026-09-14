<?php

namespace App\Http\Controllers;

use App\Models\OddsMovement;
use App\Services\Api\OddsApiService;
use App\Services\DataPipeline\PipelineLog;
use App\Services\Market\CLVTrackerService;
use App\Support\Terminal\SystemState;
use Illuminate\View\View;

/**
 * Écart de clôture (CLV) et relevés de cotes Pinnacle.
 *
 * Le CLV compare le premier relevé Pinnacle d'un match à sa clôture Pinnacle :
 * il mesure Pinnacle, jamais le prix Bet365 des prédictions. Les alertes « sharp
 * money » et leur score ne sont pas affichés : un score qui désigne quoi suivre
 * est une décision à la place de l'utilisateur (règle 4).
 */
class MarketController extends Controller
{
    public function index(CLVTrackerService $clv, OddsApiService $oddsApi): View
    {
        $states = [];

        $summary = null;
        try {
            $summary = $clv->getSummary();
        } catch (\Throwable $e) {
            PipelineLog::caught('Terminal : écart de clôture', $e);
            $states[] = new SystemState(SystemState::WARNING, 'CLV', 'Écart de clôture illisible', $e->getMessage());
        }

        // Ordre de navigation : date décroissante, puis rencontre. Jamais le CLV.
        $details = collect($summary['details'] ?? [])
            ->sortBy([['date', 'desc'], ['match', 'asc']])
            ->values();
        $overValues = $details->pluck('clv_over')->filter(fn ($v) => $v !== null);

        $movements = collect();
        try {
            $movements = OddsMovement::with('match')->orderByDesc('snapshot_at')->take(20)->get();
        } catch (\Throwable $e) {
            PipelineLog::caught('Terminal : relevés de cotes', $e);
            $states[] = new SystemState(SystemState::WARNING, 'Relevés', 'Relevés de cotes illisibles', $e->getMessage());
        }

        return view('terminal.market', [
            'summary' => $summary,
            'details' => $details,
            'over' => [
                'count' => $overValues->count(),
                'mean' => $overValues->isEmpty() ? null : round($overValues->avg(), 2),
            ],
            'movements' => $movements,
            'quota' => $oddsApi->getMonthlyUsage(),
            'bookmaker' => config('odds-api.clv_bookmaker'),
            'leagues' => config('pipeline.closing.leagues', []),
            'states' => $states,
        ]);
    }
}
