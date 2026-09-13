<?php

namespace App\Services\Betting;

/**
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * RULES SERVICE v2.0
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * 
 * Détecte et applique les règles spéciales
 * 
 * FIXES APPLIQUÉS:
 * ✅ Règle SOURCE_A_IGNORE vraiment prise en compte dans les calculs
 * ✅ Règles retournent des infos exploitables
 */
class RulesService
{
    private array $thresholds;

    public function __construct()
    {
        $this->thresholds = config('reliability.thresholds');
    }

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * DÉTECTION RÈGLES SPÉCIALES
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     */
    public function detectSpecialRule(
        string $market,
        array $predictions,
        ?array $sourceCData = null
    ): ?array {
        // RÈGLE 1: Source C >85% sur Double Chance
        if ($rule = $this->checkSourceCDoubleChance($market, $sourceCData)) {
            return $rule;
        }

        // RÈGLE 2: Source B prioritaire sur BTTS
        if ($rule = $this->checkSourceBBTTS($market, $predictions)) {
            return $rule;
        }

        // RÈGLE 3: Source A peu fiable sur Over/Under
        if ($rule = $this->checkSourceAOverUnder($market, $predictions)) {
            return $rule;
        }

        // RÈGLE 4: Source B prioritaire sur Over/Under
        if ($rule = $this->checkSourceBOverUnder($market, $predictions)) {
            return $rule;
        }

        return null;
    }

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * RÈGLE 1: Source C >85% sur Double Chance
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * Historiquement très fiable (94%)
     */
    private function checkSourceCDoubleChance(string $market, ?array $sourceCData): ?array
    {
        if ($market !== 'doubleChance' || !$sourceCData) {
            return null;
        }

        $confidence = $sourceCData['doubleChance']['confidence'] ?? 0;

        if ($confidence >= $this->thresholds['sourceC_doubleChance_high']) {
            return [
                'type' => 'SOURCE_C_HIGH',
                'confidence' => $confidence,
                'reliability' => $this->thresholds['sourceC_doubleChance_reliability'],
                'reason' => "Source C >{$this->thresholds['sourceC_doubleChance_high']}% sur Double Chance - Historiquement fiable à {$this->thresholds['sourceC_doubleChance_reliability']}%",
                'action' => 'boost', // Booste la confiance
            ];
        }

        return null;
    }

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * RÈGLE 2: Source B excellente sur BTTS
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * Fiabilité 75% historique - Poids renforcé
     */
    private function checkSourceBBTTS(string $market, array $predictions): ?array
    {
        if ($market !== 'btts') {
            return null;
        }

        $sourceBPred = collect($predictions)->firstWhere('source', 'B');

        if (!$sourceBPred) {
            return null;
        }

        $confidence = $sourceBPred['confidence'];

        if ($confidence >= $this->thresholds['sourceB_btts_priority']) {
            return [
                'type' => 'SOURCE_B_PRIORITY',
                'confidence' => $confidence,
                'reliability' => $this->thresholds['sourceB_btts_reliability'],
                'reason' => "Source B excellente sur BTTS ({$this->thresholds['sourceB_btts_reliability']}% historique) - Poids renforcé",
                'action' => 'multiply_weight', // x3 dans CalculatorService
            ];
        }

        return null;
    }

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * RÈGLE 3: Source A peu fiable sur Over/Under
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * ✅ FIX v2.0: Cette règle est maintenant vraiment appliquée
     */
    private function checkSourceAOverUnder(string $market, array $predictions): ?array
    {
        if ($market !== 'overUnder') {
            return null;
        }

        $sourceAPred = collect($predictions)->firstWhere('source', 'A');

        if (!$sourceAPred) {
            return null;
        }

        // Récupérer les prédictions B et C
        $otherPredictions = collect($predictions)
            ->where('source', '!=', 'A')
            ->pluck('value')
            ->toArray();

        // Si B et C sont d'accord MAIS différents de A
        if (
            count($otherPredictions) === 2 &&
            $otherPredictions[0] === $otherPredictions[1] &&
            $otherPredictions[0] !== $sourceAPred['value']
        ) {
            return [
                'type' => 'SOURCE_A_IGNORE',
                'reliability' => 25,
                'reason' => "Source A peu fiable sur O/U (25% historique) - Suivre Sources B et C",
                'action' => 'ignore_source_a', // ✅ Source A sera ignorée dans CalculatorService
            ];
        }

        return null;
    }

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * RÈGLE 4: Source B prioritaire sur Over/Under
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * Fiabilité 68% historique
     */
    private function checkSourceBOverUnder(string $market, array $predictions): ?array
    {
        if ($market !== 'overUnder') {
            return null;
        }

        $sourceBPred = collect($predictions)->firstWhere('source', 'B');

        if (!$sourceBPred) {
            return null;
        }

        $confidence = $sourceBPred['confidence'];

        if ($confidence >= $this->thresholds['sourceB_overUnder_priority']) {
            return [
                'type' => 'SOURCE_B_OU_PRIORITY',
                'confidence' => $confidence,
                'reliability' => $this->thresholds['sourceB_overUnder_reliability'],
                'reason' => "Source B très fiable sur O/U ({$this->thresholds['sourceB_overUnder_reliability']}% historique)",
                'action' => 'boost',
            ];
        }

        return null;
    }

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * EXPLICATION D'UNE RÈGLE (pour affichage)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     */
    public function explainRule(array $rule): string
    {
        return match ($rule['type']) {
            'SOURCE_C_HIGH' => "🌟 RÈGLE SPÉCIALE ACTIVÉE: {$rule['reason']}. Cette règle a un poids prioritaire.",
            'SOURCE_B_PRIORITY' => "⭐ {$rule['reason']}. Poids renforcé x3.",
            'SOURCE_A_IGNORE' => "⚠️ {$rule['reason']}. En cas de conflit, suivre Sources B et C.",
            'SOURCE_B_OU_PRIORITY' => "⭐ {$rule['reason']}. Confiance: {$rule['confidence']}%.",
            default => $rule['reason'] ?? '',
        };
    }
}