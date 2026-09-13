<?php

namespace App\Http\Controllers;

use App\Models\AdvancedData;
use App\Models\FootballMatch;
use App\Models\Recommendation;
use App\Models\Source;
use App\Services\AnalyzerService;
use App\Services\Betting\Layer2Service;
use App\Services\Context\ContextEnricherService;
use App\Services\DataImportService;
use App\Services\ValueAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MatchAnalysisController extends Controller
{
    private AnalyzerService $analyzerService;
    private Layer2Service $layer2Service;
    private ValueAnalyzer $valueAnalyzer;
    private DataImportService $importService;
    private ContextEnricherService $contextEnricher;

    public function __construct(
        AnalyzerService $analyzerService,
        Layer2Service $layer2Service,
        ValueAnalyzer $valueAnalyzer,
        DataImportService $importService,
        ContextEnricherService $contextEnricher
    ) {
        $this->analyzerService = $analyzerService;
        $this->layer2Service = $layer2Service;
        $this->valueAnalyzer = $valueAnalyzer;
        $this->importService = $importService;
        $this->contextEnricher = $contextEnricher;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // VUES (équivalent setView() dans React)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Page "Matchs du jour" — liste les matchs importés par le pipeline.
     */
    public function index()
    {
        $date = request('date', now()->format('Y-m-d'));

        $matches = FootballMatch::where('data_source', 'api')
            ->whereDate('match_date', $date)
            ->with('advancedData')
            ->orderBy('match_date')
            ->get();

        // Détecter les sources disponibles pour chaque match
        $matches->each(function ($match) {
            $adv = $match->advancedData;
            $match->available_sources = [
                'D' => (float) $match->odds_home > 0,
                'E' => !empty($adv?->context_data['comparison'] ?? null),
                'weather' => !empty($adv?->context_data['weather']['condition'] ?? null) && ($adv?->context_data['weather']['condition'] ?? 'unknown') !== 'unknown',
                'injuries' => !empty($adv?->sofascore_data['injuries'] ?? null),
                'h2h' => !empty($adv?->sofascore_data['h2h'] ?? null),
            ];
            $match->is_analyzed = $match->global_confidence !== null;
        });

        // Dates voisines pour navigation
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
     * Ancien formulaire de saisie manuelle (Sources A/B/C).
     */
    public function manualInput()
    {
        return view('analysis.manual-input', [
            'savedMatchesCount' => FootballMatch::count(),
        ]);
    }

    /**
     * Analyser un match existant en DB (Sources D+E automatiques).
     * Appelé en AJAX, retourne JSON.
     */
    public function analyzeExistingMatch(FootballMatch $match): JsonResponse
    {
        try {
            $match->load('advancedData', 'sources');

            // Enrichir le contexte si pas encore fait (meteo, enjeu, fatigue, arbitre, pression)
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

            // Lancer l'analyse Layer 1 (avec Sources D+E auto + A/B/C si présentes)
            $layer1Analysis = $this->analyzerService->analyze($match);

            // Layer 2 si données avancées disponibles
            $advancedData = $match->advancedData;
            $globalConfidence = $layer1Analysis['globalConfidence'];
            $layer2Score = null;
            $convergence = null;

            if ($advancedData) {
                try {
                    $matchData = [
                        'tacticalData' => $advancedData->tactical_data,
                        'sofascoreData' => $advancedData->sofascore_data,
                        'footyStatsData' => $advancedData->footystats_data,
                        'contextData' => $advancedData->context_data,
                    ];

                    $hasL2Data = !empty($matchData['tacticalData'])
                        || !empty($matchData['sofascoreData'])
                        || !empty($matchData['footyStatsData']);

                    if ($hasL2Data) {
                        $advancedAnalysis = $this->layer2Service->analyzeAdvanced($matchData, $layer1Analysis);
                        $globalConfidence = $advancedAnalysis['globalScore'];
                        $layer2Score = $advancedAnalysis['layer2Score'];
                        $convergence = $advancedAnalysis['convergence'];
                    }
                } catch (\Exception $e) {
                    Log::warning("Layer2 echoue pour match #{$match->id}, Layer1 conserve", ['error' => $e->getMessage()]);
                }
            }

            // Sauvegarder les résultats en DB
            $match->update([
                'global_confidence' => $globalConfidence,
                'layer1_score' => $layer1Analysis['globalConfidence'],
                'layer2_score' => $layer2Score,
                'convergence' => $convergence,
                'context' => json_encode($layer1Analysis['context'] ?? []),
            ]);

            // Sauvegarder les recommandations
            $match->recommendations()->delete();
            foreach ($layer1Analysis['recommendations'] as $rec) {
                $match->recommendations()->create([
                    'market' => $rec['market'],
                    'bet' => $rec['bet'],
                    'confidence' => $rec['confidence'],
                    'score' => $rec['score'],
                    'level' => $rec['level'],
                    'odds' => $rec['odds'],
                    'consensus_type' => $rec['consensus_type'],
                    'consensus_agreement' => $rec['consensus_agreement'],
                    'predictions' => $rec['predictions'],
                    'special_rule' => $rec['special_rule'] ?? null,
                    'special_rule_reason' => $rec['special_rule_reason'] ?? null,
                    'logic' => $rec['logic'] ?? null,
                    'warnings' => $rec['warnings'] ?? [],
                ]);
            }

            return response()->json([
                'success' => true,
                'match_id' => $match->id,
                'global_confidence' => $globalConfidence,
                'recommendations_count' => count($layer1Analysis['recommendations']),
            ]);

        } catch (\Exception $e) {
            Log::error("Analyse match #{$match->id} echouee", ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Page résultats
     * Affiche les 5 derniers matchs analysés (ou celui en session si disponible)
     */
    public function results(Request $request)
    {
        // Matchs récents analysés (pour le sélecteur)
        $recentMatches = FootballMatch::with(['recommendations', 'advancedData'])
            ->whereNotNull('global_confidence')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        if ($recentMatches->isEmpty()) {
            return redirect()->route('analysis.index')
                ->with('error', 'Aucun match analysé. Lancez d\'abord une analyse.');
        }

        // Si match_id en query string → charger ce match depuis la DB
        // Sinon → le plus récent de la collection
        $matchId = $request->query('match_id');
        if ($matchId) {
            $selectedMatch = FootballMatch::with(['recommendations', 'advancedData'])
                ->findOrFail((int) $matchId);
        } else {
            $selectedMatch = $recentMatches->first();
        }

        // Reconstruire les données au format attendu par les partials
        $matchData = $this->buildMatchData($selectedMatch);
        $analysis = $this->buildAnalysisData($selectedMatch);

        return view('analysis.results', [
            'match' => $matchData,
            'analysis' => $analysis,
            'allRecentMatches' => $recentMatches,
            'selectedMatchId' => $selectedMatch->id,
        ]);
    }

    /**
     * Reconstruire les données match au format array pour les partials.
     */
    private function buildMatchData(FootballMatch $match): array
    {
        $data = [
            'teams' => [
                'home' => $match->home_team,
                'away' => $match->away_team,
            ],
            'date' => $match->match_date->format('Y-m-d\TH:i'),
            'competition' => $match->competition,
            'odds' => [
                'home' => (float) $match->odds_home,
                'draw' => (float) $match->odds_draw,
                'away' => (float) $match->odds_away,
            ],
        ];

        if ($match->advancedData) {
            $data['tacticalData'] = $match->advancedData->tactical_data;
            $data['sofascoreData'] = $match->advancedData->sofascore_data;
            $data['footyStatsData'] = $match->advancedData->footystats_data;
            $data['fbrefData'] = $match->advancedData->fbref_data;
            $data['contextData'] = $match->advancedData->context_data;
        }

        return $data;
    }

    /**
     * Reconstruire les données d'analyse au format array pour les partials.
     */
    private function buildAnalysisData(FootballMatch $match): array
    {
        $analysis = [
            'globalConfidence' => $match->global_confidence,
            'context' => json_decode($match->context, true) ?? [],
            'recommendations' => $match->recommendations->map(function ($rec) {
                return [
                    'id' => $rec->id,
                    'market' => $rec->market,
                    'bet' => $rec->bet,
                    'confidence' => $rec->confidence,
                    'score' => $rec->score,
                    'level' => $rec->level,
                    'odds' => $rec->odds,
                    'consensus_type' => $rec->consensus_type,
                    'consensus_agreement' => $rec->consensus_agreement,
                    'predictions' => $rec->predictions ?? [],
                    'special_rule' => $rec->special_rule,
                    'special_rule_reason' => $rec->special_rule_reason,
                    'logic' => $rec->logic,
                    'warnings' => $rec->warnings ?? [],
                    'valueAnalysis' => $rec->value_analysis,
                    'valueVerdict' => $rec->value_verdict ?? 'NO_ODDS',
                    'valueMessage' => $rec->value_message ?? null,
                    'valueWarning' => $rec->value_warning ?? null,
                    'valueBonus' => $rec->value_bonus ?? null,
                ];
            })->toArray(),
        ];

        if ($match->advancedData) {
            $adv = $match->advancedData;
            $analysis['advancedInsights'] = [
                'globalScore' => $match->global_confidence,
                'layer1Score' => $match->layer1_score ?? $match->global_confidence,
                'layer2Score' => $match->layer2_score,
                'convergence' => $match->convergence ?? 'medium',
                'dimensions' => $adv->dimensions,
                'tacticalInsights' => $adv->tactical_insights,
                'originalRecommendations' => $adv->original_recommendations,
                'enrichedRecommendations' => $adv->enriched_recommendations ?? $analysis['recommendations'],
                'detailedExplanation' => $adv->detailed_explanation,
            ];
        }

        return $analysis;
    }

    /**
     * Page historique
     * Équivalent: view === 'history' && <HistoryView />
     */
    public function history(Request $request)
    {
        $query = FootballMatch::with(['recommendations', 'advancedData']);

        // Filtres
        if ($league = $request->query('league')) {
            $query->where('league_id', $league);
        }
        if ($status = $request->query('status')) {
            match ($status) {
                'completed' => $query->where('completed', true),
                'upcoming' => $query->where('match_date', '>', now())->where('completed', false),
                'live' => $query->where('match_date', '<=', now())->where('completed', false),
                'analyzed' => $query->whereNotNull('global_confidence'),
                default => null,
            };
        }
        if ($minConf = $request->query('min_confidence')) {
            $query->where('global_confidence', '>=', (int) $minConf);
        }
        if ($convergence = $request->query('convergence')) {
            $query->where('convergence', $convergence);
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

        // Export CSV
        if ($request->query('export') === 'csv') {
            return $this->exportHistoryCsv($query);
        }

        $matches = $query->orderByDesc('match_date')->paginate(20)->withQueryString();

        // Ligues disponibles pour le filtre
        $leagues = FootballMatch::whereNotNull('league_id')
            ->select('league_id', 'competition')
            ->distinct()
            ->orderBy('competition')
            ->get();

        return view('analysis.history', [
            'matches' => $matches,
            'leagues' => $leagues,
            'filters' => $request->only(['league', 'status', 'min_confidence', 'convergence', 'date_from', 'date_to', 'q']),
        ]);
    }

    /**
     * Export CSV de l'historique filtre.
     */
    private function exportHistoryCsv($query)
    {
        $matches = $query->orderByDesc('match_date')->get();

        return response()->streamDownload(function () use ($matches) {
            $h = fopen('php://output', 'w');
            fputcsv($h, [
                'Date', 'Competition', 'Home', 'Away', 'Score',
                'Odds Home', 'Odds Draw', 'Odds Away',
                'Confidence', 'Layer1', 'Layer2', 'Convergence',
                'Recommendations', 'Completed',
            ]);

            foreach ($matches as $m) {
                fputcsv($h, [
                    $m->match_date?->format('Y-m-d H:i'),
                    $m->competition,
                    $m->home_team,
                    $m->away_team,
                    $m->completed ? "{$m->score_home}-{$m->score_away}" : '',
                    $m->odds_home, $m->odds_draw, $m->odds_away,
                    $m->global_confidence,
                    $m->layer1_score, $m->layer2_score,
                    $m->convergence,
                    $m->recommendations->count(),
                    $m->completed ? 'yes' : 'no',
                ]);
            }
            fclose($h);
        }, 'history_' . now()->format('Ymd_His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Afficher les statistiques de performance
     */
    public function statistics()
    {
        // Récupérer tous les matchs avec validations
        $matches = FootballMatch::with([
            
            
            'advancedData'
        ])->get();
        
        // Filtrer seulement les matchs validés
        $validatedMatches = $matches->filter(function($match) {
            return $match->validations->isNotEmpty();
        });
        
        if ($validatedMatches->isEmpty()) {
            return view('analysis.statistics', [
                'hasData' => false,
            ]);
        }
        
        // Calculer les stats globales
        $stats = $this->calculateGlobalStats($validatedMatches);
        
        // Calculer les insights
        $insights = $this->calculateInsights($validatedMatches);
        
        // Comparaison Layer 1 vs Layer 2
        $layer2Comparison = $this->compareLayerPerformance($validatedMatches);
        
        // Tableau de toutes les validations
        $allValidations = $this->getAllValidations($validatedMatches);
        
        return view('analysis.statistics', [
            'hasData' => true,
            'stats' => $stats,
            'insights' => $insights,
            'layer2Comparison' => $layer2Comparison,
            'validations' => $allValidations,
            'totalMatches' => $validatedMatches->count(),
        ]);
    }

    /**
     * Calculer les statistiques globales
     */
    private function calculateGlobalStats($matches)
    {
        $totalPicks = 0;
        $successfulPicks = 0;
        
        foreach ($matches as $match) {
            foreach ($match->recommendations as $rec) {
                $validation = $rec->validations->first();
                if ($validation) {
                    $totalPicks++;
                    if ($validation->validated) {
                        $successfulPicks++;
                    }
                }
            }
        }
        
        $successRate = $totalPicks > 0 ? round(($successfulPicks / $totalPicks) * 100) : 0;
        
        return [
            'totalValidated' => $matches->count(),
            'totalPicks' => $totalPicks,
            'successfulPicks' => $successfulPicks,
            'failedPicks' => $totalPicks - $successfulPicks,
            'successRate' => $successRate,
        ];
    }

    /**
     * Calculer les insights (par marché, consensus, niveau, source)
     */
    private function calculateInsights($matches)
    {
        $byMarket = [];
        $byConsensus = [];
        $byLevel = [];
        $bySource = ['A' => [], 'B' => [], 'C' => []];
        $goldenRules = [];
        $warnings = [];
        
        foreach ($matches as $match) {
            foreach ($match->recommendations as $rec) {
                $validation = $rec->validations->first();
                if (!$validation) continue;
                
                $market = $this->formatMarketName($rec->market);
                $consensus = $rec->consensus_type ?? 'N/A';
                $level = $rec->level;
                $isSuccess = $validation->validated;
                
                // Par marché
                if (!isset($byMarket[$market])) {
                    $byMarket[$market] = ['total' => 0, 'success' => 0, 'rate' => 0];
                }
                $byMarket[$market]['total']++;
                if ($isSuccess) $byMarket[$market]['success']++;
                
                // Par consensus
                if (!isset($byConsensus[$consensus])) {
                    $byConsensus[$consensus] = ['total' => 0, 'success' => 0, 'rate' => 0];
                }
                $byConsensus[$consensus]['total']++;
                if ($isSuccess) $byConsensus[$consensus]['success']++;
                
                // Par niveau
                if (!isset($byLevel[$level])) {
                    $byLevel[$level] = ['total' => 0, 'success' => 0, 'rate' => 0];
                }
                $byLevel[$level]['total']++;
                if ($isSuccess) $byLevel[$level]['success']++;
                
                // Par source
                $predictions = is_array($rec->predictions) ? $rec->predictions : [];

                foreach ($predictions as $pred) {
                    if (!isset($pred['source'], $pred['value'])) continue;

                    $source = $pred['source'];
                    if ($pred['value'] === $rec->bet) {
                        if (!isset($bySource[$source][$market])) {
                            $bySource[$source][$market] = ['total' => 0, 'success' => 0, 'rate' => 0];
                        }
                        $bySource[$source][$market]['total']++;
                        if ($isSuccess) $bySource[$source][$market]['success']++;
                    }
                }
            }
        }
        
        // Calculer les taux
        foreach ($byMarket as &$data) {
            $data['rate'] = round(($data['success'] / $data['total']) * 100);
        }
        foreach ($byConsensus as &$data) {
            $data['rate'] = round(($data['success'] / $data['total']) * 100);
        }
        foreach ($byLevel as &$data) {
            $data['rate'] = round(($data['success'] / $data['total']) * 100);
        }
        foreach ($bySource as &$sourceData) {
            foreach ($sourceData as &$data) {
                $data['rate'] = round(($data['success'] / $data['total']) * 100);
            }
        }
        
        // Générer les règles d'or
        if (isset($byConsensus['TOTAL']) && $byConsensus['TOTAL']['rate'] >= 85) {
            $goldenRules[] = "✅ Consensus TOTAL : {$byConsensus['TOTAL']['rate']}% de réussite ({$byConsensus['TOTAL']['success']}/{$byConsensus['TOTAL']['total']})";
        }
        
        if (isset($byLevel[1]) && $byLevel[1]['rate'] >= 80) {
            $goldenRules[] = "✅ Picks PRIORITÉ 1 : {$byLevel[1]['rate']}% de réussite ({$byLevel[1]['success']}/{$byLevel[1]['total']})";
        }
        
        foreach ($byMarket as $market => $data) {
            if ($data['rate'] >= 80 && $data['total'] >= 5) {
                $goldenRules[] = "✅ Marché {$market} : {$data['rate']}% de réussite ({$data['success']}/{$data['total']})";
            }
        }
        
        // Warnings
        if (isset($byConsensus['CONFLIT']) && $byConsensus['CONFLIT']['rate'] < 60) {
            $warnings[] = "⚠️ Consensus CONFLIT peu fiable : {$byConsensus['CONFLIT']['rate']}% seulement";
        }
        
        foreach ($byMarket as $market => $data) {
            if ($data['rate'] < 60 && $data['total'] >= 5) {
                $warnings[] = "⚠️ Marché {$market} peu fiable : {$data['rate']}% seulement";
            }
        }
        
        return [
            'byMarket' => $byMarket,
            'byConsensus' => $byConsensus,
            'byLevel' => $byLevel,
            'bySource' => $bySource,
            'goldenRules' => $goldenRules,
            'warnings' => $warnings,
        ];
    }

    /**
     * Comparer les performances Layer 1 vs Layer 2
     */
    private function compareLayerPerformance($matches)
    {
        $layer2Matches = $matches->filter(function($match) {
            return $match->advancedData !== null;
        });
        
        if ($layer2Matches->isEmpty()) {
            return null;
        }
        
        $layer1Total = 0;
        $layer2BetterPredictions = 0;
        $layer1BetterPredictions = 0;
        
        foreach ($layer2Matches as $match) {
            $rawOriginal = $match->advancedData->original_recommendations;

            // Décodage sécurisé
            $originalRecs = is_string($rawOriginal) 
                ? json_decode($rawOriginal, true) 
                : ($rawOriginal ?? []);

            if (empty($originalRecs)) continue;
            
            foreach ($match->recommendations as $index => $rec) {
                $validation = $rec->validations->first();
                if (!$validation || !isset($originalRecs[$index])) continue;
                
                $recL1 = $originalRecs[$index];
                $recL2 = $rec;
                
                $isCorrect = $validation->validated;
                $confDiff = $recL2->confidence - $recL1['confidence'];
                
                $layer1Total++;
                
                // Analyser si Layer 2 a mieux prédit
                if (($confDiff < 0 && !$isCorrect) || ($confDiff > 0 && $isCorrect)) {
                    $layer2BetterPredictions++;
                } elseif (($confDiff > 0 && !$isCorrect) || ($confDiff < 0 && $isCorrect)) {
                    $layer1BetterPredictions++;
                }
            }
        }
        
        $layer2AccuracyRate = $layer1Total > 0 ? round(($layer2BetterPredictions / $layer1Total) * 100) : 0;
        
        return [
            'matchCount' => $layer2Matches->count(),
            'layer1Total' => $layer1Total,
            'layer2BetterPredictions' => $layer2BetterPredictions,
            'layer1BetterPredictions' => $layer1BetterPredictions,
            'layer2AccuracyRate' => $layer2AccuracyRate,
        ];
    }

    private function getAllValidations($matches)
    {
        $validations = collect();

        foreach ($matches as $match) {
            foreach ($match->recommendations as $rec) {
                $validation = $rec->validations->first();
                if (!$validation) continue;
                
                $predictions = $rec->predictions ?? [];

                $validations->push([
                    'type' => 'recommendation',
                    'date' => $match->validated_at ?? $match->created_at,
                    'match' => "{$match->home_team} vs {$match->away_team}",
                    'competition' => $match->competition,
                    'market' => $rec->market,
                    'pick' => $rec->bet,
                    'result' => $validation->validated,
                    'confidence' => $rec->confidence,
                    'level' => $rec->level,
                    'consensus' => $rec->consensus_type,
                    'predictions' => $predictions,
                    'sourceA' => collect($predictions)->firstWhere('source', 'A'),
                    'sourceB' => collect($predictions)->firstWhere('source', 'B'),
                    'sourceC' => collect($predictions)->firstWhere('source', 'C'),
                ]);
            }
            
            // Ajouter aussi les combos
            foreach ($match->combos as $combo) {
                $validation = MatchValidation::where('match_id', $match->id)
                    ->where('is_combo', true)
                    ->first();
                
                if ($validation) {
                    $validations->push([
                        'type' => 'combo',
                        'date' => $match->validated_at ?? $match->created_at,
                        'match' => "{$match->home_team} vs {$match->away_team}",
                        'competition' => $match->competition,
                        'market' => 'COMBO',
                        'pick' => $combo->name,
                        'result' => $validation->validated,
                        'confidence' => $combo->confidence,
                        'level' => null,
                        'consensus' => 'N/A',
                        'predictions' => [],
                        'sourceA' => null,
                        'sourceB' => null,
                        'sourceC' => null,
                    ]);
                }
            }
        }

        // Trier par date décroissante
        return $validations->sortByDesc('date')->values();
    }

    /**
     * Importer des données depuis un fichier (JSON ou CSV)
     */
    public function importData(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:json,csv,txt|max:10240', // 10MB max
            'format' => 'required|in:json_complete,json_analysis,csv'
        ]);

        try {
            $report = $this->importService->import(
                $request->file('file'),
                $request->input('format')
            );

            return back()->with('success', [
                'message' => 'Import réussi !',
                'report' => $report
            ]);

        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de l\'import : ' . $e->getMessage());
        }
    }

    /**
     * Exporter les données au format JSON complet
     */
    public function exportJsonComplete()
    {
        $validatedMatches = FootballMatch::where('validated', true)
            ->with([ 'combos', 'sources', 'advancedData'])
            ->orderBy('validated_at', 'desc')
            ->get();

        $insights = $this->calculateInsights($validatedMatches);

        $export = [
            'metadata' => [
                'exportDate' => now()->toISOString(),
                'totalMatches' => $validatedMatches->count(),
                'totalValidations' => $this->countTotalValidations($validatedMatches),
                'version' => '2.0'
            ],
            'insights' => $insights,
            'matches' => $validatedMatches->map(function ($match) {
                return [
                    'id' => $match->react_id ?? $match->id,
                    'teams' => [
                        'home' => $match->home_team,
                        'away' => $match->away_team,
                    ],
                    'date' => $match->match_date->toISOString(),
                    'competition' => $match->competition,
                    'odds' => $this->formatOdds($match),
                    'context' => $match->context,
                    'globalConfidence' => $match->global_confidence,
                    'validations' => [
                        'recommendations' => $this->formatRecommendationValidations($match),
                        'combos' => $this->formatComboValidations($match),
                    ],
                    'recommendations' => $match->recommendations->map(function ($rec, $index) {
                        $predictions = $rec->predictions ?? [];
                        
                        return [
                            'index' => $index,
                            'market' => $rec->market,
                            'bet' => $rec->bet,
                            'result' => $rec->validated,
                            'confidence' => $rec->confidence,
                            'score' => $rec->score,
                            'level' => $rec->level,
                            'odds' => $rec->odds,
                            'consensus' => [
                                'type' => $rec->consensus_type,
                                'agreement' => $rec->consensus_agreement,
                            ],
                            'predictions' => $predictions,
                            'specialRule' => $rec->special_rule ? [
                                'type' => $rec->special_rule,
                                'reason' => $rec->special_rule_reason,
                            ] : null,
                            'logic' => $rec->logic,
                        ];
                    })->values(),
                    'combos' => $match->combos->map(function ($combo, $index) {
                        return [
                            'index' => $index,
                            'name' => $combo->name,
                            'result' => $combo->validated,
                            'bets' => $combo->bets,
                            'totalOdds' => $combo->total_odds,
                            'confidence' => $combo->confidence,
                            'priority' => $combo->priority,
                        ];
                    })->values(),
                ];
            }),
        ];

        $fileName = 'football-analyzer-export-' . now()->format('Y-m-d') . '.json';

        return response()->json($export)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"");
    }

    /**
     * Exporter les données au format JSON analyse (détaillé comme React)
     */
    public function exportJsonAnalysis()
    {
        $validatedMatches = FootballMatch::where('validated', true)
            ->with([ 'combos'])
            ->orderBy('validated_at', 'desc')
            ->get();

        $insights = $this->calculateInsights($validatedMatches);
        $allValidations = $this->getAllValidations($validatedMatches);
        $stats = $this->calculateGlobalStats($validatedMatches);

        // Filtrer les cas intéressants avec TOUS les détails
        $failedPicks = $allValidations
            ->filter(fn($v) => !$v['result'] && $v['type'] === 'recommendation')
            ->map(function($v) {
                $predictions = is_string($v['predictions']) 
                    ? json_decode($v['predictions'], true) 
                    : ($v['predictions'] ?? []);
                
                $sourceA = collect($predictions)->firstWhere('source', 'A');
                $sourceB = collect($predictions)->firstWhere('source', 'B');
                $sourceC = collect($predictions)->firstWhere('source', 'C');
                
                return [
                    'match' => $v['match'],
                    'date' => Carbon::parse($v['date'])->format('Y-m-d'),
                    'market' => $this->formatMarketName($v['market']),
                    'pick' => $v['pick'],
                    'confidence' => $v['confidence'],
                    'consensus' => $v['consensus'],
                    'sources' => [
                        'A' => $sourceA ? ($sourceA['value'] . ' (' . $sourceA['confidence'] . '%)') : 'N/A',
                        'B' => $sourceB ? ($sourceB['value'] . ' (' . $sourceB['confidence'] . '%)') : 'N/A',
                        'C' => $sourceC ? ($sourceC['value'] . ' (' . $sourceC['confidence'] . '%)') : 'N/A',
                    ],
                    'specialRule' => $v['specialRule'] ?? null,
                    'reason' => 'ÉCHEC - Analyser pourquoi'
                ];
            })
            ->values();

        $conflictPicks = $allValidations
            ->filter(fn($v) => $v['consensus'] === 'CONFLIT' && $v['type'] === 'recommendation')
            ->map(function($v) {
                $predictions = is_string($v['predictions']) 
                    ? json_decode($v['predictions'], true) 
                    : ($v['predictions'] ?? []);
                
                $sourceA = collect($predictions)->firstWhere('source', 'A');
                $sourceB = collect($predictions)->firstWhere('source', 'B');
                $sourceC = collect($predictions)->firstWhere('source', 'C');
                
                return [
                    'match' => $v['match'],
                    'market' => $this->formatMarketName($v['market']),
                    'pick' => $v['pick'],
                    'result' => $v['result'] ? 'SUCCESS' : 'FAILED',
                    'sources' => [
                        'A' => $sourceA ? ($sourceA['value'] . ' (' . $sourceA['confidence'] . '%)') : 'N/A',
                        'B' => $sourceB ? ($sourceB['value'] . ' (' . $sourceB['confidence'] . '%)') : 'N/A',
                        'C' => $sourceC ? ($sourceC['value'] . ' (' . $sourceC['confidence'] . '%)') : 'N/A',
                    ],
                    'reason' => 'CONFLIT entre sources'
                ];
            })
            ->values();

        $lowConfPicks = $allValidations
            ->filter(fn($v) => ($v['confidence'] ?? 100) < 70 && $v['type'] === 'recommendation')
            ->map(function($v) {
                return [
                    'match' => $v['match'],
                    'market' => $this->formatMarketName($v['market']),
                    'pick' => $v['pick'],
                    'confidence' => $v['confidence'],
                    'result' => $v['result'] ? 'SUCCESS' : 'FAILED',
                    'reason' => 'Confiance faible (<70%)'
                ];
            })
            ->values();

        $export = [
            'metadata' => [
                'exportDate' => now()->toISOString(),
                'exportType' => 'ANALYSIS',
                'totalMatches' => $validatedMatches->count(),
                'totalValidations' => $allValidations->count(),
                'period' => [
                    'start' => $validatedMatches->last()?->validated_at?->toISOString(),
                    'end' => $validatedMatches->first()?->validated_at?->toISOString(),
                ],
            ],
            'globalStats' => [
                'totalMatches' => $validatedMatches->count(),
                'totalPicks' => $allValidations->where('type', 'recommendation')->count(),
                'successRate' => $stats['successRate'],
                'totalCombos' => $allValidations->where('type', 'combo')->count(),
            ],
            'insights' => $insights,
            'interestingCases' => [
                'failedPicks' => $failedPicks,
                'conflictPicks' => $conflictPicks,
                'lowConfidencePicks' => $lowConfPicks,
            ],
            'detailedAnalysis' => [
                'bestMarkets' => $this->getBestMarkets($insights),
                'worstMarkets' => $this->getWorstMarkets($insights),
                'bestSources' => $this->getBestSources($insights),
                'consensusAnalysis' => collect($insights['byConsensus'] ?? [])->map(function($data, $type) {
                    return [
                        'consensusType' => $type,
                        'successRate' => $data['rate'],
                        'picks' => "{$data['success']}/{$data['total']}",
                        'recommendation' => $data['rate'] >= 80 ? 'FIABLE' : ($data['rate'] >= 70 ? 'CORRECT' : 'PRUDENCE')
                    ];
                })->values(),
            ],
            'recommendations' => [
                '1. Analyser les cas d\'échec pour identifier les patterns à éviter',
                '2. Vérifier si certaines sources sont systématiquement meilleures sur certains marchés',
                '3. Comparer les performances selon le type de consensus',
                '4. Identifier les conditions optimales (ex: Source B + Consensus TOTAL + conf >80%)',
                '5. Créer des règles personnalisées basées sur ces observations',
            ],
        ];

        $fileName = 'football-analyzer-analysis-' . now()->format('Y-m-d') . '.json';

        return response()->json($export)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"");
    }

    /**
     * Exporter au format CSV
     */
    public function exportCsv()
    {
        $validatedMatches = FootballMatch::where('validated', true)
            ->with([])
            ->orderBy('validated_at', 'desc')
            ->get();

        $allValidations = $this->getAllValidations($validatedMatches);

        $headers = [
            'Date',
            'Match',
            'Competition',
            'Market',
            'Pick',
            'Result',
            'Confidence',
            'Source_A_Value',
            'Source_A_Conf',
            'Source_B_Value',
            'Source_B_Conf',
            'Source_C_Value',
            'Source_C_Conf',
            'Consensus',
        ];

        $rows = $allValidations->map(function ($v) {
            return [
                Carbon::parse($v['date'])->format('Y-m-d'),
                "\"{$v['match']}\"",
                "\"{$v['competition']}\"",
                $this->formatMarketName($v['market']),
                $v['pick'],
                $v['result'] ? 'WIN' : 'LOSS',
                $v['confidence'] ?? '',
                $v['sourceA']['value'] ?? '',
                $v['sourceA']['confidence'] ?? '',
                $v['sourceB']['value'] ?? '',
                $v['sourceB']['confidence'] ?? '',
                $v['sourceC']['value'] ?? '',
                $v['sourceC']['confidence'] ?? '',
                $v['consensus'] ?? '',
            ];
        });

        $csv = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\n";
        }

        $fileName = 'football-analyzer-export-' . now()->format('Y-m-d') . '.csv';

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$fileName}\"");
    }

    private function formatOdds($match)
    {
        return [
            '1' => $match->odds_home,
            'X' => $match->odds_draw,
            '2' => $match->odds_away,
            'Over2.5' => $match->odds_over_2_5,
            'Under2.5' => $match->odds_under_2_5,
            'BTTS_Yes' => $match->odds_btts_yes,
            'BTTS_No' => $match->odds_btts_no,
            'DC_1X' => $match->odds_dc_1x,
            'DC_12' => $match->odds_dc_12,
            'DC_X2' => $match->odds_dc_x2,
        ];
    }

    private function formatRecommendationValidations($match)
    {
        return $match->recommendations->mapWithKeys(function ($rec, $index) {
            return [$index => ['validated' => $rec->validated]];
        })->toArray();
    }

    private function formatComboValidations($match)
    {
        return $match->combos->mapWithKeys(function ($combo, $index) {
            return [$index => ['validated' => $combo->validated]];
        })->toArray();
    }

    private function countTotalValidations($matches)
    {
        return $matches->sum(function ($match) {
            return $match->recommendations->count() + $match->combos->count();
        });
    }

    private function formatMarketName($market)
    {
        $names = [
            'winner' => '1X2',
            'overUnder' => 'O/U 2.5',
            'btts' => 'BTTS',
            'doubleChance' => 'DC',
            'exactScore' => 'Score',
        ];

        return $names[$market] ?? $market;
    }

    private function getBestMarkets($insights)
    {
        return collect($insights['byMarket'] ?? [])
            ->filter(fn($data) => $data['rate'] >= 75 && $data['total'] >= 3)
            ->sortByDesc('rate')
            ->map(fn($data, $market) => [
                'market' => $market,
                'successRate' => $data['rate'],
                'picks' => "{$data['success']}/{$data['total']}",
                'recommendation' => $data['rate'] >= 80 ? 'FORTEMENT RECOMMANDÉ' : 'RECOMMANDÉ',
            ])
            ->values();
    }

    private function getWorstMarkets($insights)
    {
        return collect($insights['byMarket'] ?? [])
            ->filter(fn($data) => $data['rate'] < 65 && $data['total'] >= 3)
            ->sortBy('rate')
            ->map(fn($data, $market) => [
                'market' => $market,
                'successRate' => $data['rate'],
                'picks' => "{$data['success']}/{$data['total']}",
                'recommendation' => 'À ÉVITER',
            ])
            ->values();
    }

    private function getBestSources($insights)
    {
        $sourcePerf = [];
        
        foreach (['A', 'B', 'C'] as $source) {
            foreach ($insights['bySource'][$source] ?? [] as $market => $data) {
                if ($data['rate'] >= 75 && $data['total'] >= 3) {
                    $sourcePerf[] = [
                        'source' => $source,
                        'market' => $market,
                        'successRate' => $data['rate'],
                        'picks' => "{$data['success']}/{$data['total']}",
                    ];
                }
            }
        }

        return collect($sourcePerf)->sortByDesc('successRate')->values();
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACTIONS (équivalent handlers dans React)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Analyser plusieurs matchs
     * Équivalent: handleAnalyze()
     */
    public function analyze(Request $request)
    {
        try {
            $matchesData = $request->input('matches', []);
            
            if (empty($matchesData)) {
                return back()->with('error', 'Aucun match fourni');
            }

            $results = [];
            $processedMatches = [];

            foreach ($matchesData as $matchData) {
                $tempMatch = new FootballMatch([
                    'home_team' => $matchData['teams']['home'],
                    'away_team' => $matchData['teams']['away'],
                    'match_date' => $matchData['date'],
                    'competition' => $matchData['competition'],
                    'odds_home' => $matchData['odds']['home'],
                    'odds_draw' => $matchData['odds']['draw'],
                    'odds_away' => $matchData['odds']['away'],
                    'odds_over_2_5' => $matchData['odds']['over_2_5'] ?? null,
                    'odds_under_2_5' => $matchData['odds']['under_2_5'] ?? null,
                    'odds_btts_yes' => $matchData['odds']['btts_yes'] ?? null,
                    'odds_btts_no' => $matchData['odds']['btts_no'] ?? null,
                    'odds_dc_1x' => $matchData['odds']['dc_1x'] ?? null,
                    'odds_dc_12' => $matchData['odds']['dc_12'] ?? null,
                    'odds_dc_x2' => $matchData['odds']['dc_x2'] ?? null,
                    'odds_home_over_0_5' => $matchData['odds']['home_over_0_5'] ?? null,
                    'odds_home_under_0_5' => $matchData['odds']['home_under_0_5'] ?? null,
                    'odds_home_over_1_5' => $matchData['odds']['home_over_1_5'] ?? null,
                    'odds_home_under_1_5' => $matchData['odds']['home_under_1_5'] ?? null,
                    'odds_away_over_0_5' => $matchData['odds']['away_over_0_5'] ?? null,
                    'odds_away_under_0_5' => $matchData['odds']['away_under_0_5'] ?? null,
                    'odds_away_over_1_5' => $matchData['odds']['away_over_1_5'] ?? null,
                    'odds_away_under_1_5' => $matchData['odds']['away_under_1_5'] ?? null,
                ]);

                $tempSources = collect([
                    $this->createTempSource('A', $matchData['sources']['sourceA']),
                    $this->createTempSource('B', $matchData['sources']['sourceB']),
                    $this->createTempSource('C', $matchData['sources']['sourceC']),
                ]);
                
                $tempMatch->setRelation('sources', $tempSources);

                $layer1Analysis = $this->analyzerService->analyze($tempMatch);

                if (!empty($layer1Analysis['recommendations'])) {
                    foreach ($layer1Analysis['recommendations'] as &$rec) {
                        $valueAnalysis = $this->valueAnalyzer->analyzeRecommendation($rec, $tempMatch);
                        $rec['valueAnalysis'] = $valueAnalysis;
                    }
                }
                
                $hasAdvancedData = !empty($matchData['tacticalData']) 
                    || !empty($matchData['sofascoreData']) 
                    || !empty($matchData['footyStatsData']);

                if ($hasAdvancedData) {
                    $advancedAnalysis = $this->layer2Service->analyzeAdvanced($matchData, $layer1Analysis);
                    
                    $results[] = array_merge($layer1Analysis, [
                        'globalConfidence' => $advancedAnalysis['globalScore'],
                        'advancedInsights' => $advancedAnalysis,
                    ]);
                } else {
                    $results[] = $layer1Analysis;
                }
                
                $processedMatches[] = $matchData;
            }

            session([
                'currentMatches' => $processedMatches,
                'analysisResults' => $results,
            ]);

            return redirect()->route('analysis.results');

        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de l\'analyse: ' . $e->getMessage());
        }
    }

    /**
     * Sauvegarder UN match analysé
     */
    public function save()
    {
        $match = session('currentMatches.0');
        $analysis = session('analysisResults.0');
        
        if (!$match || !$analysis) {
            return back()->with('error', 'Aucun match à sauvegarder');
        }
        
        try {
            DB::beginTransaction();
            
            $footballMatch = FootballMatch::create([
                'home_team' => $match['teams']['home'],
                'away_team' => $match['teams']['away'],
                'match_date' => $match['date'],
                'competition' => $match['competition'] ?? 'Non spécifiée',
                'odds_home' => $match['odds']['home'] ?? 1.0,
                'odds_draw' => $match['odds']['draw'] ?? 1.0,
                'odds_away' => $match['odds']['away'] ?? 1.0,
                'odds_over_2_5' => $match['odds']['over_2_5'] ?? null,
                'odds_under_2_5' => $match['odds']['under_2_5'] ?? null,
                'odds_btts_yes' => $match['odds']['btts_yes'] ?? null,
                'odds_btts_no' => $match['odds']['btts_no'] ?? null,
                'odds_dc_1x' => $match['odds']['dc_1x'] ?? null,
                'odds_dc_12' => $match['odds']['dc_12'] ?? null,
                'odds_dc_x2' => $match['odds']['dc_x2'] ?? null,
                'odds_home_over_0_5' => $match['odds']['home_over_0_5'] ?? null,
                'odds_home_under_0_5' => $match['odds']['home_under_0_5'] ?? null,
                'odds_home_over_1_5' => $match['odds']['home_over_1_5'] ?? null,
                'odds_home_under_1_5' => $match['odds']['home_under_1_5'] ?? null,
                'odds_away_over_0_5' => $match['odds']['away_over_0_5'] ?? null,
                'odds_away_under_0_5' => $match['odds']['away_under_0_5'] ?? null,
                'odds_away_over_1_5' => $match['odds']['away_over_1_5'] ?? null,
                'odds_away_under_1_5' => $match['odds']['away_under_1_5'] ?? null,
                'global_confidence' => $analysis['globalConfidence'] ?? 0,
                'layer1_score' => $analysis['layer1Score'] ?? null,
                'layer2_score' => $analysis['advancedInsights']['layer2Score'] ?? null,
                'convergence' => $analysis['advancedInsights']['convergence'] ?? null,
                'context' => json_encode($analysis['context'] ?? []),
            ]);
            
            foreach (['A', 'B', 'C'] as $sourceType) {
                $sourceKey = 'source' . $sourceType;
                
                if (!empty($match['sources'][$sourceKey])) {
                    $sourceData = $match['sources'][$sourceKey];
                    
                    if ($sourceType === 'C') {
                        $winnerData = $sourceData['winner'] ?? [];
                        $maxProba = max($winnerData['home'] ?? 0, $winnerData['draw'] ?? 0, $winnerData['away'] ?? 0);
                        
                        if ($maxProba === ($winnerData['home'] ?? 0)) {
                            $winnerPick = '1';
                            $winnerConf = $winnerData['home'];
                        } elseif ($maxProba === ($winnerData['draw'] ?? 0)) {
                            $winnerPick = 'X';
                            $winnerConf = $winnerData['draw'];
                        } else {
                            $winnerPick = '2';
                            $winnerConf = $winnerData['away'];
                        }
                        
                        Source::create([
                            'match_id' => $footballMatch->id,
                            'source_type' => $sourceType,
                            'winner_prediction' => $winnerPick,
                            'winner_confidence' => $winnerConf,
                            'over_under_prediction' => $sourceData['overUnder']['pick'] ?? null,
                            'over_under_confidence' => $sourceData['overUnder']['confidence'] ?? null,
                            'btts_prediction' => $sourceData['btts']['pick'] ?? null,
                            'btts_confidence' => $sourceData['btts']['confidence'] ?? null,
                            'exact_score' => $sourceData['exactScore']['pick'] ?? null,
                            'exact_score_confidence' => $sourceData['exactScore']['confidence'] ?? null,
                            'predictions' => json_encode($sourceData),
                        ]);
                    } else {
                        Source::create([
                            'match_id' => $footballMatch->id,
                            'source_type' => $sourceType,
                            'winner_prediction' => $sourceData['winner']['pick'] ?? null,
                            'winner_confidence' => $sourceData['winner']['confidence'] ?? null,
                            'over_under_prediction' => $sourceData['overUnder']['pick'] ?? null,
                            'over_under_confidence' => $sourceData['overUnder']['confidence'] ?? null,
                            'btts_prediction' => $sourceData['btts']['pick'] ?? null,
                            'btts_confidence' => $sourceData['btts']['confidence'] ?? null,
                            'exact_score' => $sourceData['exactScore']['pick'] ?? null,
                            'exact_score_confidence' => $sourceData['exactScore']['confidence'] ?? null,
                            'predictions' => json_encode($sourceData),
                        ]);
                    }
                }
            }
            
            foreach ($analysis['recommendations'] ?? [] as $rec) {
                Recommendation::create([
                    'match_id' => $footballMatch->id,
                    'market' => $rec['market'] ?? '',
                    'bet' => $rec['bet'] ?? '',
                    'confidence' => $rec['confidence'] ?? 0,
                    'score' => $rec['score'] ?? 0,
                    'level' => $rec['level'] ?? 3,
                    'odds' => $rec['odds'] ?? 1.0,
                    'consensus_type' => $rec['consensus_type'] ?? null,
                    'consensus_agreement' => $rec['consensus_agreement'] ?? null,
                    'predictions' => json_encode($rec['predictions'] ?? []),
                    'special_rule' => $rec['special_rule'] ?? null,
                    'special_rule_reason' => $rec['special_rule_reason'] ?? null,
                    'logic' => $rec['logic'] ?? '',
                    'warnings' => json_encode($rec['warnings'] ?? []),
                    'value_analysis' => json_encode($rec['valueAnalysis'] ?? null),
                    'value_verdict' => $rec['valueVerdict'] ?? null,
                    'value_message' => $rec['valueMessage'] ?? null,
                    'value_warning' => $rec['valueWarning'] ?? null,
                    'value_bonus' => $rec['valueBonus'] ?? null,
                ]);
            }
            
            if (!empty($analysis['advancedInsights'])) {
                AdvancedData::create([
                    'match_id' => $footballMatch->id,
                    'tactical_data' => json_encode($match['tacticalData'] ?? null),
                    'sofascore_data' => json_encode($match['sofascoreData'] ?? null),
                    'footystats_data' => json_encode($match['footyStatsData'] ?? null),
                    'fbref_data' => json_encode($match['fbrefData'] ?? null),
                    'context_data' => json_encode($match['contextData'] ?? null),
                    'dimensions' => json_encode($analysis['advancedInsights']['dimensions'] ?? null),
                    'tactical_insights' => json_encode($analysis['advancedInsights']['tacticalInsights'] ?? null),
                    'original_recommendations' => json_encode($analysis['advancedInsights']['originalRecommendations'] ?? null),
                    'enriched_recommendations' => json_encode($analysis['advancedInsights']['enrichedRecommendations'] ?? null),
                    'detailed_explanation' => $analysis['advancedInsights']['detailedExplanation'] ?? null,
                ]);

                Log::info('Enriched recs à sauvegarder', $analysis['advancedInsights']['enrichedrecommendations'] ?? []);
                // dd(json_encode($analysis['advancedInsights']['enrichedRecommendations'] ?? null));
            }
            DB::commit();
            
            session()->forget(['currentMatches', 'analysisResults']);
            
            return redirect()->route('analysis.results')
                ->with('success', '✅ Match sauvegardé avec succès !');
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur sauvegarde match: ' . $e->getMessage());
            Log::error('Match data: ' . json_encode($match));
            Log::error($e->getTraceAsString());
            
            return back()->with('error', 'Erreur lors de la sauvegarde: ' . $e->getMessage());
        }
    }

    /**
     * Sauvegarder tous les matchs analysés
     * Équivalent: handleSaveAll()
     */
    public function saveAll(Request $request)
    {
        $matches = session('currentMatches', []);
        $results = session('analysisResults', []);
        
        if (empty($matches) || empty($results)) {
            return back()->with('error', 'Aucun match à sauvegarder');
        }
        
        try {
            $savedCount = 0;
            
            foreach ($matches as $index => $matchData) {
                $analysis = $results[$index] ?? null;
                if (!$analysis) continue;
                
                // 1. Créer le FootballMatch
                $footballMatch = FootballMatch::create([
                    'home_team' => $matchData['teams']['home'] ?? 'Unknown',
                    'away_team' => $matchData['teams']['away'] ?? 'Unknown',
                    'match_date' => $matchData['date'] ?? now(),
                    'competition' => $matchData['competition'] ?? null,
                    'odds_home' => $matchData['odds']['home'] ?? null,
                    'odds_draw' => $matchData['odds']['draw'] ?? null,
                    'odds_away' => $matchData['odds']['away'] ?? null,
                    'global_confidence' => $analysis['globalConfidence'] ?? 0,
                    'layer1_score' => $analysis['layer1Score'] ?? null,
                    'layer2_score' => $analysis['advancedInsights']['layer2Score'] ?? null,
                    'convergence' => $analysis['advancedInsights']['convergence'] ?? null,
                    'context' => $matchData['contextData'] ?? null,
                ]);
                
                // 2. Créer les Sources (A, B, C)
                foreach (['A', 'B', 'C'] as $sourceType) {
                    $sourceKey = 'source' . $sourceType;
                    if (!empty($matchData['sources'][$sourceKey])) {
                        $sourceData = $matchData['sources'][$sourceKey];
                        Source::create([
                            'match_id' => $footballMatch->id,
                            'source_type' => $sourceType,
                            'winner_prediction' => $sourceData['winner'] ?? null,
                            'winner_confidence' => $sourceData['winnerConfidence'] ?? null,
                            'over_under_prediction' => $sourceData['overUnder'] ?? null,
                            'over_under_confidence' => $sourceData['overUnderConfidence'] ?? null,
                            'btts_prediction' => $sourceData['btts'] ?? null,
                            'btts_confidence' => $sourceData['bttsConfidence'] ?? null,
                            'exact_score' => $sourceData['exactScore'] ?? null,
                            'exact_score_confidence' => $sourceData['exactScoreConfidence'] ?? null,
                        ]);
                    }
                }
                
                // 3. Créer les Recommendations
                foreach ($analysis['recommendations'] ?? [] as $rec) {
                    Recommendation::create([
                        'match_id' => $footballMatch->id,
                        'market' => $rec['market'] ?? '',
                        'bet' => $rec['bet'] ?? '',
                        'confidence' => $rec['confidence'] ?? 0,
                        'confidence_layer1' => $rec['confidenceLayer1'] ?? null,
                        'score' => $rec['score'] ?? 0,
                        'level' => $rec['level'] ?? 3,
                        'odds' => $rec['odds'] ?? null,
                        'consensus_type' => $rec['consensusType'] ?? null,
                        'consensus_agreement' => $rec['consensusAgreement'] ?? null,
                        'predictions' => $rec['predictions'] ?? [],
                        'special_rule' => $rec['specialRule'] ?? null,
                        'special_rule_reason' => $rec['specialRuleReason'] ?? null,
                        'logic' => $rec['logic'] ?? '',
                        'warnings' => $rec['warnings'] ?? [],
                    ]);
                }
                
                // 4. Créer AdvancedData si données Layer 2
                $advancedInsights = $analysis['advancedInsights'] ?? null;
                if ($advancedInsights) {
                    AdvancedData::create([
                        'match_id' => $footballMatch->id,
                        'tactical_data' => $matchData['tacticalData'] ?? null,
                        'sofascore_data' => $matchData['sofascoreData'] ?? null,
                        'footystats_data' => $matchData['footyStatsData'] ?? null,
                        'context_data' => $matchData['contextData'] ?? null,
                        'dimensions' => $advancedInsights['dimensions'] ?? null,
                        'tactical_insights' => $advancedInsights['tacticalInsights'] ?? null,
                        'original_recommendations' => $advancedInsights['originalRecommendations'] ?? null,
                        'detailed_explanation' => $advancedInsights['detailedExplanation'] ?? null,
                    ]);
                }
                
                $savedCount++;
            }
            
            return back()->with('success', "✅ {$savedCount} match(s) sauvegardé(s) !");
            
        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de la sauvegarde: ' . $e->getMessage());
        }
    }

    /**
     * Nouveau match (reset)
     * Équivalent: handleNewMatch()
     */
    public function newMatch()
    {
        session()->forget(['currentMatches', 'analysisResults']);
        return redirect()->route('analysis.index');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // API ENDPOINTS (pour appels AJAX/fetch)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * API: Analyser matchs (retourne JSON)
     */
    public function apiAnalyze(Request $request): JsonResponse
    {
        try {
            $matchesData = $request->input('matches', []);
            
            if (empty($matchesData)) {
                return response()->json(['success' => false, 'error' => 'Aucun match fourni'], 400);
            }

            $results = [];

            foreach ($matchesData as $matchData) {
                $layer1Analysis = $this->analyzerService->analyze($matchData);
                
                $hasAdvancedData = !empty($matchData['tacticalData']) 
                    || !empty($matchData['sofascoreData']) 
                    || !empty($matchData['footyStatsData']);

                if ($hasAdvancedData) {
                    $advancedAnalysis = $this->layer2Service->analyzeAdvanced($matchData, $layer1Analysis);
                    
                    $results[] = [
                        'matchData' => $matchData,
                        'analysis' => array_merge($layer1Analysis, [
                            'recommendations' => $advancedAnalysis['enrichedRecommendations'],
                            'globalConfidence' => $advancedAnalysis['globalScore'],
                            'advancedInsights' => $advancedAnalysis,
                        ]),
                    ];
                } else {
                    $results[] = [
                        'matchData' => $matchData,
                        'analysis' => $layer1Analysis,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'count' => count($results),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MÉTHODES HISTORY (CRUD)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Afficher le detail d'un match.
     */
    public function show($id)
    {
        $footballMatch = FootballMatch::with(['sources', 'recommendations', 'advancedData'])->findOrFail($id);

        $match = $this->buildMatchData($footballMatch);
        $analysis = $this->buildAnalysisData($footballMatch);

        return view('analysis.show', compact('footballMatch', 'match', 'analysis'));
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

    /**
     * Dashboard principal (vue d'ensemble)
     */
    public function dashboard()
    {
        $totalMatches = FootballMatch::count();
        $completedMatches = FootballMatch::where('completed', true)->count();
        $analyzedMatches = FootballMatch::whereNotNull('global_confidence')->count();
        $matchesToday = FootballMatch::whereDate('match_date', now()->format('Y-m-d'))->count();

        // Stats depuis le backtest le plus récent
        $latestBacktest = \App\Models\BacktestRun::where('status', 'completed')
            ->orderByDesc('created_at')
            ->first();

        // Performance par marché (depuis les prédictions backtest)
        $marketPerf = [];
        if ($latestBacktest && $latestBacktest->by_market) {
            foreach ($latestBacktest->by_market as $row) {
                $marketPerf[] = ['label' => $row['label'], 'rate' => $row['win_rate']];
            }
        }

        // Combos récents
        $recentCombos = \App\Models\DailyCombo::where('rank', 1)
            ->orderByDesc('date')
            ->take(5)
            ->get();

        $oddsQuota = ['used' => 0, 'limit' => 500, 'remaining' => 500];
        try {
            $oddsQuota = app(\App\Services\Api\OddsApiService::class)->getMonthlyUsage();
        } catch (\Exception $e) {}

        return view('dashboard', [
            'totalMatches'      => $totalMatches,
            'completedMatches'  => $completedMatches,
            'analyzedMatches'   => $analyzedMatches,
            'matchesToday'      => $matchesToday,
            'backtestRun'       => $latestBacktest,
            'backtestWinRate'   => $latestBacktest?->win_rate ?? 0,
            'backtestROI'       => $latestBacktest?->roi ?? 0,
            'backtestYield'     => $latestBacktest?->yield_pct ?? 0,
            'backtestDrawdown'  => $latestBacktest?->max_drawdown ?? 0,
            'bankrollCurve'     => $latestBacktest?->bankroll_curve ?? [],
            'marketPerf'        => $marketPerf,
            'recentCombos'      => $recentCombos,
            'avgConfidence'     => round(FootballMatch::avg('global_confidence') ?? 0),
            'recentMatches'     => FootballMatch::with('recommendations')
                                    ->orderBy('created_at', 'desc')
                                    ->take(8)
                                    ->get(),
            'oddsQuota'         => $oddsQuota,
        ]);
    }

    private function createTempSource(string $type, array $sourceData): \App\Services\TempSource
    {
        return new \App\Services\TempSource($type, $sourceData);
    }
}
