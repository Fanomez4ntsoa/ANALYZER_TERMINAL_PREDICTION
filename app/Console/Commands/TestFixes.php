<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\AnalyzerService;
use App\Services\Betting\CalculatorService;
use App\Services\Betting\RulesService;
use App\Services\ValueAnalyzer;
use Illuminate\Console\Command;

class TestFixes extends Command
{
    protected $signature = 'test:fixes';
    protected $description = 'Test des fixes v2.0 sur les 33 matchs validés (Source A: 100% vs 50%)';

    private AnalyzerService $analyzer;
    private CalculatorService $calculator;
    private RulesService $rules;
    private ValueAnalyzer $valueAnalyzer;

    public function __construct(ValueAnalyzer $valueAnalyzer, AnalyzerService $analyzer)
    {
        parent::__construct();
        $this->analyzer = $analyzer;
        $this->valueAnalyzer = $valueAnalyzer;
        $this->calculator = new CalculatorService();
        $this->rules = new RulesService();
    }

    public function handle()
    {
        $this->info("🧪 SIMULATION v1.0 vs v2.0 sur matchs validés");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->newLine();

        // Charger les matchs validés
        $matches = FootballMatch::with(['sources', 'recommendations', 'validations'])
            ->whereNotNull('react_id')
            ->where('validated', true)
            ->get();
        
        if ($matches->isEmpty()) {
            $this->error("❌ Aucun match validé trouvé !");
            return 1;
        }

        $total = $matches->count();
        $this->info("✅ {$total} matchs chargés");
        $this->newLine();

        // Statistiques v1.0 et v2.0
        $statsV1 = $this->initStats();
        $statsV2 = $this->initStats();

        // Progress bar
        $bar = $this->output->createProgressBar($total * 2);
        $this->line("Simulation en cours...");
        $bar->start();

        foreach ($matches as $match) {
            // Tester v1.0 (Source A = 100%)
            $resultsV1 = $this->analyzeWithVersion($match, 'v1', $bar);
            $this->updateStats($statsV1, $resultsV1, $match);

            // Tester v2.0 (Source A = 50%)
            $resultsV2 = $this->analyzeWithVersion($match, 'v2', $bar);
            $this->updateStats($statsV2, $resultsV2, $match);
        }

        $bar->finish();
        $this->newLine(2);

        // Afficher les résultats
        $this->displayComparison($statsV1, $statsV2);

        return 0;
    }

    /**
     * Analyse un match avec une version spécifique
     */
    private function analyzeWithVersion(FootballMatch $match, string $version, $bar): array
    {
        $sources = $match->sources;
        $markets = ['winner', 'overUnder', 'btts', 'doubleChance'];
        $results = [];

        foreach ($markets as $market) {
            $predictions = $this->extractPredictions($sources, $market, $version);
            
            if (empty($predictions)) {
                continue;
            }

            $sourceC = $sources->firstWhere('source_type', 'C');
            $sourceCData = null;

            if ($sourceC) {
                // Utiliser getDecodedPredictions() via un accesseur temporaire
                $raw = $sourceC->getRawOriginal('predictions');
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_string($decoded)) {
                        $decoded = json_decode($decoded, true);
                    }
                    $sourceCData = is_array($decoded) ? $decoded : null;
                } else {
                    $sourceCData = $sourceC->predictions;
                }
            }
            $specialRule = $this->rules->detectSpecialRule($market, $predictions, $sourceCData);
            
            $confidence = $this->calculator->calculateWeightedConfidence(
                $market, 
                $predictions, 
                $sourceCData,
                $specialRule
            );

            $results[$market] = [
                'confidence' => round($confidence),
                'predictions' => $predictions,
            ];
        }

        $bar->advance();
        return $results;
    }

    /**
     * Extrait les prédictions avec confidence selon version
     */
    private function extractPredictions($sources, string $market, string $version): array
    {
        $predictions = [];

        foreach ($sources as $source) {
            $pick = $source->getPick($market);
            $confidence = $source->getConfidence($market);

            if ($pick === null) {
                continue;
            }

            $actualConfidence = $confidence ?? 50;

            // ✅ DIFFÉRENCE v1.0 vs v2.0
            if ($source->source_type === 'A') {
                $actualConfidence = ($version === 'v1') ? 100 : 50;
            } else {
                $actualConfidence = $confidence ?? 50;
            }

            $predictions[] = [
                'source' => $source->source_type,
                'value' => $pick,
                'confidence' => $actualConfidence,
            ];
        }

        return $predictions;
    }

    
    private function updateStats(array &$stats, array $results, FootballMatch $match): void
    {
        // 🔍 DEBUG - Afficher pour le PREMIER match seulement
        static $debugDisplayed = false;

        if (!$debugDisplayed) {
            $this->newLine();
            $this->warn("🔍 DEBUG PREMIER MATCH (ID: {$match->id}):");
            dump([
                'match_id' => $match->id,
                'home' => $match->home_team,
                'away' => $match->away_team,
                'results_markets' => array_keys($results),
                'recommendations_count' => $match->recommendations->count(),
                'recommendations_markets' => $match->recommendations->pluck('market')->toArray(),
                'validated_true' => $match->recommendations->where('validated', true)->count(),
                'validated_false' => $match->recommendations->where('validated', false)->count(),
                'validated_null' => $match->recommendations->whereNull('validated')->count(),
            ]);
            $debugDisplayed = true;
        }

        // Récupérer TOUTES les recommandations du match (déjà chargées via with())
        $validatedRecs = $match->recommendations->keyBy('market');

        foreach ($results as $market => $result) {
            $realRec = $validatedRecs->get($market);

            if (!$realRec) {
                continue;
            }

            $stats['markets'][$market]['total']++;
            $stats['total']++;

            // Si la recommandation a réussi (validated = 1 ou true)
            if ($realRec->validated === true || $realRec->validated === 1) {
                $stats['markets'][$market]['success']++;
                $stats['success']++;
            }
        }
    }

    /**
     * Initialise les statistiques
     */
    private function initStats(): array
    {
        return [
            'total' => 0,
            'success' => 0,
            'markets' => [
                'doubleChance' => ['total' => 0, 'success' => 0],
                'btts' => ['total' => 0, 'success' => 0],
                'overUnder' => ['total' => 0, 'success' => 0],
                'winner' => ['total' => 0, 'success' => 0],
            ],
        ];
    }

    /**
     * Affiche la comparaison v1 vs v2
     */
    private function displayComparison(array $v1, array $v2): void
    {
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("📊 RÉSULTATS DE LA SIMULATION");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->newLine();

        // Global
        $globalV1 = $this->calculateRate($v1['success'], $v1['total']);
        $globalV2 = $this->calculateRate($v2['success'], $v2['total']);
        $globalDiff = $globalV2 - $globalV1;

        $this->table(
            ['Version', 'Taux global', 'Réussis/Total'],
            [
                [
                    'v1.0 (Source A = 100%)',
                    $this->formatRate($globalV1),
                    "{$v1['success']}/{$v1['total']}"
                ],
                [
                    'v2.0 (Source A = 50%)',
                    $this->formatRate($globalV2) . ' ' . $this->formatDiff($globalDiff),
                    "{$v2['success']}/{$v2['total']}"
                ],
            ]
        );

        $this->newLine();

        // Par marché
        $this->line("📋 DÉTAIL PAR MARCHÉ:");
        $this->newLine();

        $marketNames = [
            'doubleChance' => 'Double Chance',
            'btts' => 'BTTS',
            'overUnder' => 'Over/Under',
            'winner' => 'Winner (1X2)',
        ];

        $tableData = [];

        foreach ($marketNames as $key => $name) {
            $rateV1 = $this->calculateRate(
                $v1['markets'][$key]['success'],
                $v1['markets'][$key]['total']
            );
            $rateV2 = $this->calculateRate(
                $v2['markets'][$key]['success'],
                $v2['markets'][$key]['total']
            );
            $diff = $rateV2 - $rateV1;

            $tableData[] = [
                $name,
                $this->formatRate($rateV1),
                $this->formatRate($rateV2),
                $this->formatDiff($diff),
                "{$v1['markets'][$key]['success']}/{$v1['markets'][$key]['total']}",
            ];
        }

        $this->table(
            ['Marché', 'v1.0', 'v2.0', 'Diff', 'Réussis/Total'],
            $tableData
        );

        $this->newLine();
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // Conclusion
        if ($globalDiff > 0) {
            $this->info("✅ AMÉLIORATION CONFIRMÉE : +{$this->formatRate($globalDiff, false)}");
            $this->line("💡 Les fixes v2.0 sont meilleurs que v1.0 !");
        } elseif ($globalDiff < 0) {
            $this->warn("⚠️ RÉGRESSION : {$this->formatRate($globalDiff, false)}");
            $this->line("💡 Les fixes v2.0 sont moins bons. À ajuster.");
        } else {
            $this->line("⚖️ ÉGALITÉ : Aucune différence significative");
        }

        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    /**
     * Calcule un taux de réussite
     */
    private function calculateRate(int $success, int $total): float
    {
        return $total > 0 ? ($success / $total) * 100 : 0;
    }

    /**
     * Formate un taux en %
     */
    private function formatRate(float $rate, bool $withSymbol = true): string
    {
        $formatted = number_format($rate, 1);
        return $withSymbol ? "{$formatted}%" : $formatted;
    }

    /**
     * Formate une différence avec couleur
     */
    private function formatDiff(float $diff): string
    {
        if ($diff > 0) {
            return "<fg=green>+{$this->formatRate($diff)}</>";
        } elseif ($diff < 0) {
            return "<fg=red>{$this->formatRate($diff)}</>";
        }
        return "<fg=gray>=0.0%</>";
    }
}