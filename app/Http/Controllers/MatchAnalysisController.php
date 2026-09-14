<?php

namespace App\Http\Controllers;

use App\Models\AdvancedData;
use App\Models\FootballMatch;
use App\Services\Context\ContextEnricherService;
use App\Services\Probability\PredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MatchAnalysisController extends Controller
{
    public function __construct(
        private PredictionService $predictionService,
        private ContextEnricherService $contextEnricher,
    ) {
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MATCHS DU JOUR
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Page "Matchs du jour" — liste les matchs importés par le pipeline.
     */
    public function index()
    {
        $date = request('date', now()->format('Y-m-d'));

        $matches = FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $date)
            ->with(['advancedData', 'predictions'])
            ->orderBy('match_date')
            ->get();

        // Données disponibles pour chaque match (affichage uniquement)
        $matches->each(function ($match) {
            $adv = $match->advancedData;
            $match->available_data = [
                'cotes' => (float) $match->odds_home > 0,
                'comparison' => !empty($adv?->context_data['comparison'] ?? null),
                'weather' => !empty($adv?->context_data['weather']['condition'] ?? null) && ($adv?->context_data['weather']['condition'] ?? 'unknown') !== 'unknown',
                'injuries' => !empty($adv?->sofascore_data['injuries'] ?? null),
                'h2h' => !empty($adv?->sofascore_data['h2h'] ?? null),
            ];
            $match->is_analyzed = $match->predictions->isNotEmpty();
            $match->computed_at = $match->predictions->first()?->computed_at;
        });

        $prevDate = \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d');
        $nextDate = \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d');

        return view('analysis.index', [
            'matches' => $matches,
            'date' => $date,
            'prevDate' => $prevDate,
            'nextDate' => $nextDate,
        ]);
    }

    /**
     * Calculer les prédictions d'un match existant en DB.
     * Appelé en AJAX, retourne JSON.
     */
    public function analyzeExistingMatch(FootballMatch $match): JsonResponse
    {
        try {
            $match->load('advancedData');

            // Enrichir le contexte (fatigue, enjeu, météo, arbitre, pression) si pas encore fait.
            // Ne nourrit pas le modèle xG : constitue un historique de features
            // collectées avant le coup d'envoi (voir context_data.collected_at).
            $contextData = $match->advancedData?->context_data ?? [];
            $needsEnrichment = empty($contextData['weather']) || empty($contextData['fatigue']);

            if ($needsEnrichment) {
                try {
                    $enrichedContext = $this->contextEnricher->enrich($match);
                    AdvancedData::updateOrCreate(
                        ['match_id' => $match->id],
                        ['context_data' => $enrichedContext]
                    );
                    $match->load('advancedData');
                } catch (\Exception $e) {
                    Log::warning("Context enrichment echoue pour match #{$match->id}", ['error' => $e->getMessage()]);
                }
            }

            $predictions = $this->predictionService->computeAndStore($match);

            return response()->json([
                'success' => true,
                'match_id' => $match->id,
                'predictions_count' => $predictions->count(),
                'computed_at' => $predictions->first()?->computed_at?->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error("Prediction match #{$match->id} echouee", ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PRÉDICTIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Page prédictions : le match demandé (?match_id=) ou le dernier calculé,
     * avec un sélecteur des matchs récents.
     */
    public function results(Request $request)
    {
        $recentMatches = FootballMatch::with('predictions')
            ->whereHas('predictions')
            ->orderByDesc('match_date')
            ->take(10)
            ->get();

        if ($recentMatches->isEmpty()) {
            return redirect()->route('analysis.index')
                ->with('error', 'Aucune prédiction calculée. Lancez d\'abord une analyse.');
        }

        $matchId = $request->query('match_id');
        $selectedMatch = $matchId
            ? FootballMatch::with('predictions')->findOrFail((int) $matchId)
            : $recentMatches->first();

        return view('analysis.results', [
            'footballMatch' => $selectedMatch,
            'predictionsByMarket' => $this->groupPredictions($selectedMatch),
            'allRecentMatches' => $recentMatches,
            'selectedMatchId' => $selectedMatch->id,
        ]);
    }

    /**
     * Détail d'un match (historique).
     */
    public function show($id)
    {
        $footballMatch = FootballMatch::with(['predictions', 'advancedData'])->findOrFail($id);

        return view('analysis.show', [
            'footballMatch' => $footballMatch,
            'predictionsByMarket' => $this->groupPredictions($footballMatch),
        ]);
    }

    /**
     * Regrouper les prédictions par marché dans l'ordre d'affichage.
     */
    private function groupPredictions(FootballMatch $match): array
    {
        $order = ['winner', 'doubleChance', 'overUnder25', 'btts'];
        $grouped = $match->predictions->groupBy('market');

        $result = [];
        foreach ($order as $market) {
            if ($grouped->has($market)) {
                $result[$market] = $grouped[$market]->values();
            }
        }

        return $result;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HISTORIQUE
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function history(Request $request)
    {
        $query = FootballMatch::with('predictions')->withCount('predictions');

        if ($league = $request->query('league')) {
            $query->where('league_id', $league);
        }
        if ($status = $request->query('status')) {
            match ($status) {
                'completed' => $query->where('completed', true),
                'upcoming' => $query->where('match_date', '>', now())->where('completed', false),
                'live' => $query->where('match_date', '<=', now())->where('completed', false),
                'analyzed' => $query->whereHas('predictions'),
                default => null,
            };
        }
        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('match_date', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('match_date', '<=', $dateTo);
        }
        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('home_team', 'like', "%{$search}%")
                  ->orWhere('away_team', 'like', "%{$search}%");
            });
        }

        if ($request->query('export') === 'csv') {
            return $this->exportHistoryCsv($query);
        }

        $matches = $query->orderByDesc('match_date')->paginate(20)->withQueryString();

        $leagues = FootballMatch::whereNotNull('league_id')
            ->select('league_id', 'competition')
            ->distinct()
            ->orderBy('competition')
            ->get();

        return view('analysis.history', [
            'matches' => $matches,
            'leagues' => $leagues,
            'filters' => $request->only(['league', 'status', 'date_from', 'date_to', 'q']),
        ]);
    }

    /**
     * Export CSV de l'historique filtré : une ligne par prédiction.
     */
    private function exportHistoryCsv($query)
    {
        $matches = $query->orderByDesc('match_date')->get();

        return response()->streamDownload(function () use ($matches) {
            $h = fopen('php://output', 'w');
            fputcsv($h, [
                'Match ID', 'Date', 'Competition', 'Home', 'Away', 'Score', 'Completed',
                'Market', 'Outcome', 'Model Prob', 'Odds', 'Implied Prob', 'Fair Prob', 'Edge',
                'Bookmaker', 'Odds Taken At', 'Computed At',
            ]);

            foreach ($matches as $m) {
                $base = [
                    $m->id,
                    $m->match_date?->format('Y-m-d H:i'),
                    $m->competition,
                    $m->home_team,
                    $m->away_team,
                    $m->completed ? "{$m->score_home}-{$m->score_away}" : '',
                    $m->completed ? 'yes' : 'no',
                ];

                if ($m->predictions->isEmpty()) {
                    fputcsv($h, array_merge($base, array_fill(0, 10, '')));
                    continue;
                }

                foreach ($m->predictions as $p) {
                    fputcsv($h, array_merge($base, [
                        $p->market,
                        $p->outcome,
                        $p->model_probability,
                        $p->odds,
                        $p->implied_probability,
                        $p->fair_probability,
                        $p->edge,
                        $p->bookmaker,
                        $p->odds_taken_at?->format('Y-m-d H:i:s'),
                        $p->computed_at?->format('Y-m-d H:i:s'),
                    ]));
                }
            }
            fclose($h);
        }, 'history_' . now()->format('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Supprimer un match
     */
    public function deleteMatch(FootballMatch $match)
    {
        try {
            $match->delete(); // Cascade delete via foreign keys
            return back()->with('success', 'Match supprimé !');
        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de la suppression');
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // DASHBOARD
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function dashboard()
    {
        $totalMatches = FootballMatch::count();
        $completedMatches = FootballMatch::where('completed', true)->count();
        $analyzedMatches = FootballMatch::whereHas('predictions')->count();
        $matchesToday = FootballMatch::whereDate('match_date', now()->format('Y-m-d'))->count();

        $oddsQuota = ['used' => 0, 'limit' => 500, 'remaining' => 500];
        try {
            $oddsQuota = app(\App\Services\Api\OddsApiService::class)->getMonthlyUsage();
        } catch (\Exception $e) {}

        return view('dashboard', [
            'totalMatches'      => $totalMatches,
            'completedMatches'  => $completedMatches,
            'analyzedMatches'   => $analyzedMatches,
            'matchesToday'      => $matchesToday,
            'recentMatches'     => FootballMatch::withCount('predictions')
                                    ->orderBy('created_at', 'desc')
                                    ->take(8)
                                    ->get(),
            'oddsQuota'         => $oddsQuota,
        ]);
    }
}
