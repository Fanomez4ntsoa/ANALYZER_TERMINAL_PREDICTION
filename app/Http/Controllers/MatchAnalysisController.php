<?php

namespace App\Http\Controllers;

use App\Models\AdvancedData;
use App\Models\FootballMatch;
use App\Models\Prediction;
use App\Models\PredictionLogEntry;
use App\Services\Context\ContextEnricherService;
use App\Services\Probability\KickoffPassedException;
use App\Services\Probability\PredictionService;
use App\Support\Terminal\MarketLabel;
use Carbon\CarbonImmutable;
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
     * Page « Matchs » : les matchs importés d'une date, les données disponibles et
     * le calcul à la demande. Seuls les matchs à venir, non contaminés et avec
     * cotes 1X2 complètes sont calculables (même règle que predictions:compute).
     */
    public function index(Request $request)
    {
        $date = $this->dateParam($request->query('date'));

        $matches = FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $date->toDateString())
            ->with(['advancedData', 'predictions'])
            ->orderBy('match_date')
            ->orderBy('id')
            ->get();

        // Données disponibles pour chaque match (affichage uniquement)
        $matches->each(function (FootballMatch $match) {
            $adv = $match->advancedData;
            $match->available_data = array_keys(array_filter([
                'cotes' => (float) $match->odds_home > 0,
                'comparaison' => !empty($adv?->context_data['comparison'] ?? null),
                'météo' => !empty($adv?->context_data['weather']['condition'] ?? null) && ($adv?->context_data['weather']['condition'] ?? 'unknown') !== 'unknown',
                'blessures' => !empty($adv?->sofascore_data['injuries'] ?? null),
                'h2h' => !empty($adv?->sofascore_data['h2h'] ?? null),
            ]));
            $match->computed_at = $match->predictions->first()?->computed_at;
            $match->can_compute = !$match->hasKickedOff()
                && !$match->post_kickoff_data
                && (float) $match->odds_home > 0 && (float) $match->odds_draw > 0 && (float) $match->odds_away > 0;
        });

        return view('terminal.matches', [
            'matches' => $matches,
            'date' => $date,
        ]);
    }

    private function dateParam(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
            } catch (\Throwable) {
                // date invalide : aujourd'hui
            }
        }

        return CarbonImmutable::now('UTC')->startOfDay();
    }

    /**
     * Calculer les prédictions d'un match existant en DB.
     * Appelé en AJAX, retourne JSON.
     */
    public function analyzeExistingMatch(FootballMatch $match): JsonResponse
    {
        try {
            $match->load('advancedData');

            // Enrichir le contexte (fatigue, enjeu, météo, pression) si pas encore fait.
            // Ne nourrit pas le modèle xG : constitue un historique de features
            // collectées avant le coup d'envoi (voir context_data.collected_at).
            $contextData = $match->advancedData?->context_data ?? [];
            // Jamais après le coup d'envoi : la donnée serait post coup d'envoi.
            $needsEnrichment = !$match->hasKickedOff()
                && (empty($contextData['weather']) || empty($contextData['fatigue']));

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

            $predictions = $this->predictionService->computeAndStore($match, PredictionLogEntry::TRIGGER_MANUAL);

            return response()->json([
                'success' => true,
                'match_id' => $match->id,
                'predictions_count' => $predictions->count(),
                'computed_at' => $predictions->first()?->computed_at?->toIso8601String(),
            ]);

        } catch (KickoffPassedException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Match commencé : les probabilités ne sont plus calculées après le coup d\'envoi.',
            ], 409);
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
     * Ancienne page « prédictions » : renvoie au détail du match demandé
     * (?match_id=) ou du dernier match calculé.
     */
    public function results(Request $request)
    {
        $matchId = $request->query('match_id')
            ?? FootballMatch::whereHas('predictions')->orderByDesc('match_date')->value('id');

        return $matchId === null
            ? redirect()->route('analysis.index')
            : redirect()->route('history.show', (int) $matchId);
    }

    /**
     * Détail d'un match : cotes, paramètres enregistrés et prédictions dans
     * l'ordre du catalogue des marchés.
     */
    public function show($id)
    {
        $footballMatch = FootballMatch::with('predictions')->findOrFail($id);

        return view('terminal.match', [
            'match' => $footballMatch,
            'predictions' => $footballMatch->predictions
                ->sortBy(fn (Prediction $p) => MarketLabel::sortKey($p->market, $p->outcome))
                ->values(),
        ]);
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

        return view('terminal.history', [
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
                'Match ID', 'Date', 'Competition', 'Home', 'Away', 'Score', 'Completed', 'Post Kickoff Data',
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
                    $m->post_kickoff_data ? 'yes' : 'no',
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
}
