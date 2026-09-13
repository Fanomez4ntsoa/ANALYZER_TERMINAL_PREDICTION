<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\Betting\CalculatorService;
use App\Services\Betting\RulesService;
use Illuminate\Console\Command;

class TestConfidence extends Command
{
    protected $signature = 'test:confidence';
    protected $description = 'Compare la confiance et la calibration v1.0 vs v2.0';

    private CalculatorService $calculator;
    private RulesService $rules;

    public function __construct()
    {
        parent::__construct();
        $this->calculator = new CalculatorService();
        $this->rules = new RulesService();
    }

    public function handle()
    {
        $this->info("🧪 ANALYSE DE CONFIANCE v1.0 vs v2.0");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->newLine();

        // Charger les matchs validés
        $matches = FootballMatch::with(['sources', 'recommendations'])
            ->whereNotNull('react_id')
            ->where('validated', true)
            ->get();

        if ($matches->isEmpty()) {
            $this->error("❌ Aucun match validé trouvé !");
            return 1;
        }

        $this->info("✅ {$matches->count()} matchs chargés");
        $this->newLine();

        // Collecter les données
        $dataV1 = $this->collectConfidenceData($matches, 'v1');
        $dataV2 = $this->collectConfidenceData($matches, 'v2');

        // Afficher les résultats
        $this->displayResults($dataV1, $dataV2);

        return 0;
    }

    /**
     * Collecte les données de confiance pour une version
     */
    private function collectConfidenceData($matches, string $version): array
    {
        $data = [
            'picks' => [],
            'confidences' => [],
            'by_market' => [
                'doubleChance' => ['confidences' => [], 'successes' => [], 'failures' => []],
                'btts' => ['confidences' => [], 'successes' => [], 'failures' => []],
                'overUnder' => ['confidences' => [], 'successes' => [], 'failures' => []],
                'winner' => ['confidences' => [], 'successes' => [], 'failures' => []],
            ],
        ];

        $markets = ['winner', 'overUnder', 'btts', 'doubleChance'];

        foreach ($matches as $match) {
            $sources = $match->sources;

            foreach ($markets as $market) {
                $predictions = $this->extractPredictions($sources, $market, $version);

                if (empty($predictions)) {
                    continue;
                }

                // Calculer la confiance
                $sourceC = $sources->firstWhere('source_type', 'C');
                $sourceCData = $this->getSourceCData($sourceC);
                $specialRule = $this->rules->detectSpecialRule($market, $predictions, $sourceCData);

                $confidence = $this->calculator->calculateWeightedConfidence(
                    $market,
                    $predictions,
                    $sourceCData,
                    $specialRule
                );

                // Trouver le résultat réel
                $realRec = $match->recommendations->firstWhere('market', $market);

                if ($realRec) {
                    $success = $realRec->validated === true || $realRec->validated === 1;

                    $data['picks'][] = [
                        'market' => $market,
                        'confidence' => $confidence,
                        'success' => $success,
                    ];

                    $data['confidences'][] = $confidence;
                    $data['by_market'][$market]['confidences'][] = $confidence;

                    if ($success) {
                        $data['by_market'][$market]['successes'][] = $confidence;
                    } else {
                        $data['by_market'][$market]['failures'][] = $confidence;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Extrait predictions avec confidence selon version
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

            // FIX v2.0: Source A = 50% au lieu de 100%
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

    /**
     * Récupère les données de Source C
     */
    private function getSourceCData($sourceC): ?array
    {
        if (!$sourceC) {
            return null;
        }

        $raw = $sourceC->getRawOriginal('predictions');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            return is_array($decoded) ? $decoded : null;
        }

        return $sourceC->predictions;
    }

    /**
     * Affiche les résultats
     */
    private function displayResults(array $v1, array $v2): void
    {
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("📊 RÉSULTATS - ANALYSE DE CONFIANCE");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->newLine();

        // Statistiques globales
        $avgV1 = count($v1['confidences']) > 0 ? array_sum($v1['confidences']) / count($v1['confidences']) : 0;
        $avgV2 = count($v2['confidences']) > 0 ? array_sum($v2['confidences']) / count($v2['confidences']) : 0;

        $this->table(
            ['Métrique', 'v1.0 (Source A=100%)', 'v2.0 (Source A=50%)', 'Différence'],
            [
                [
                    'Confiance moyenne',
                    number_format($avgV1, 1) . '%',
                    number_format($avgV2, 1) . '%',
                    $this->formatDiff($avgV2 - $avgV1)
                ],
                [
                    'Total picks analysés',
                    count($v1['picks']),
                    count($v2['picks']),
                    '='
                ],
            ]
        );

        $this->newLine();

        // Distribution
        $this->line("📊 DISTRIBUTION DES CONFIANCES:");
        $this->newLine();

        $ranges = [
            [90, 100, 'Très haute (90-100%)'],
            [80, 89, 'Haute (80-89%)'],
            [70, 79, 'Moyenne-haute (70-79%)'],
            [60, 69, 'Moyenne (60-69%)'],
            [50, 59, 'Basse (50-59%)'],
        ];

        $distributionData = [];
        foreach ($ranges as [$min, $max, $label]) {
            $countV1 = $this->countInRange($v1['confidences'], $min, $max);
            $countV2 = $this->countInRange($v2['confidences'], $min, $max);
            $percentV1 = count($v1['confidences']) > 0 ? ($countV1 / count($v1['confidences'])) * 100 : 0;
            $percentV2 = count($v2['confidences']) > 0 ? ($countV2 / count($v2['confidences'])) * 100 : 0;

            $distributionData[] = [
                $label,
                "{$countV1} (" . number_format($percentV1, 1) . "%)",
                "{$countV2} (" . number_format($percentV2, 1) . "%)",
                $this->formatDiff($percentV2 - $percentV1)
            ];
        }

        $this->table(
            ['Plage', 'v1.0', 'v2.0', 'Diff'],
            $distributionData
        );

        $this->newLine();

        // Calibration par marché
        $this->line("🎯 CALIBRATION PAR MARCHÉ:");
        $this->line("(Confiance moyenne sur réussites vs échecs)");
        $this->newLine();

        $marketNames = [
            'doubleChance' => 'Double Chance',
            'btts' => 'BTTS',
            'overUnder' => 'Over/Under',
            'winner' => 'Winner',
        ];

        $calibrationData = [];
        foreach ($marketNames as $key => $name) {
            $successAvgV1 = $this->average($v1['by_market'][$key]['successes']);
            $failureAvgV1 = $this->average($v1['by_market'][$key]['failures']);
            $successAvgV2 = $this->average($v2['by_market'][$key]['successes']);
            $failureAvgV2 = $this->average($v2['by_market'][$key]['failures']);

            $overconfidenceV1 = $successAvgV1 > 0 ? $failureAvgV1 - $successAvgV1 : 0;
            $overconfidenceV2 = $successAvgV2 > 0 ? $failureAvgV2 - $successAvgV2 : 0;

            $calibrationData[] = [
                $name,
                number_format($successAvgV1, 1) . '%',
                number_format($failureAvgV1, 1) . '%',
                $this->formatOverconfidence($overconfidenceV1),
                number_format($successAvgV2, 1) . '%',
                number_format($failureAvgV2, 1) . '%',
                $this->formatOverconfidence($overconfidenceV2),
            ];
        }

        $this->table(
            ['Marché', 'v1 Réussi', 'v1 Échoué', 'v1 Sur-conf.', 'v2 Réussi', 'v2 Échoué', 'v2 Sur-conf.'],
            $calibrationData
        );

        $this->newLine();
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // Conclusion
        $confidenceDrop = $avgV1 - $avgV2;
        if ($confidenceDrop > 2) {
            $this->info("✅ v2.0 est PLUS PRUDENT : -{$this->format($confidenceDrop)} de confiance moyenne");
            $this->line("💡 Cela devrait réduire les pertes sur picks trop confiants.");
        } elseif ($confidenceDrop < -2) {
            $this->warn("⚠️ v2.0 est PLUS CONFIANT : +{$this->format(abs($confidenceDrop))} de confiance moyenne");
        } else {
            $this->line("⚖️ Peu de différence dans la confiance moyenne");
        }

        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    private function countInRange(array $values, float $min, float $max): int
    {
        return count(array_filter($values, fn($v) => $v >= $min && $v <= $max));
    }

    private function average(array $values): float
    {
        return count($values) > 0 ? array_sum($values) / count($values) : 0;
    }

    private function format(float $value): string
    {
        return number_format($value, 1) . '%';
    }

    private function formatDiff(float $diff): string
    {
        if (abs($diff) < 0.1) return '<fg=gray>=0.0%</>';
        $formatted = ($diff > 0 ? '+' : '') . number_format($diff, 1) . '%';
        return $diff > 0 ? "<fg=green>{$formatted}</>" : "<fg=red>{$formatted}</>";
    }

    private function formatOverconfidence(float $value): string
    {
        if ($value > 5) {
            return "<fg=red>+{$this->format($value)}</>";
        } elseif ($value > 2) {
            return "<fg=yellow>+{$this->format($value)}</>";
        } elseif ($value < -2) {
            return "<fg=green>{$this->format($value)}</>";
        }
        return "<fg=gray>{$this->format($value)}</>";
    }
}