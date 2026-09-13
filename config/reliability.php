<?php

/**
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * FIABILITÉ DES SOURCES - BASÉE SUR 33 MATCHS VALIDÉS
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * 
 * Version 2.0 - Décembre 2024
 * Stats issues de l'historique React importé
 */

return [
    
    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * FIABILITÉ PAR SOURCE ET PAR MARCHÉ
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * 
     * Format: reliability (0.00 à 1.00)
     * Calculé sur base: taux de réussite / total
     */
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HIÉRARCHIE DES SOURCES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //
    // D = Modèle maison (Poisson + xG + cotes) → POIDS PRINCIPAL
    // E = Prédictions API-Football             → POIDS SECONDAIRE
    // A/B/C = Sites externes (saisie manuelle) → OPTIONNELS, bonus si présents
    //
    // Le système fonctionne correctement avec D+E seuls (sans A/B/C).
    //
    'sources' => [

        // Source D — Modèle probabiliste maison (PRINCIPAL)
        // Fiabilité haute car calibré sur les cotes du marché (écart 3.2%)
        'D' => [
            'doubleChance' => 0.92,  // Très fiable (Poisson + cotes)
            'winner' => 0.80,        // Principal signal 1X2
            'overUnder' => 0.75,     // Bon mais biais Under connu
            'btts' => 0.70,          // Poisson BTTS fiable
            'exactScore' => 0.15,    // Poisson seul → limité
        ],

        // Source E — Prédictions API-Football (SECONDAIRE)
        // Comparaison 7 dimensions + conseil expert
        'E' => [
            'doubleChance' => 0.82,  // API-Football bon sur DC
            'winner' => 0.65,        // Correct sur 1X2
            'overUnder' => 0.55,     // Limité (basé sur le conseil texte)
            'btts' => 0.50,          // Pas de prédiction directe
            'exactScore' => 0.05,    // Pas de prédiction
        ],

        // Sources A/B/C — Sites externes (OPTIONNELS)
        // Inchangés — fiabilités empiriques sur 33 matchs
        // Utilisés seulement si présents (saisie manuelle)
        'A' => [
            'doubleChance' => 0.86,  // 32/37 = 86% ✅
            'btts' => 0.63,          // 22/35 = 63%
            'winner' => 0.60,        // 26/43 = 60%
            'overUnder' => 0.61,     // 19/31 = 61%
            'exactScore' => 0.00,    // N/A
        ],

        'B' => [
            'doubleChance' => 0.90,  // 38/42 = 90% ✅✅
            'overUnder' => 0.68,     // 27/40 = 68% ✅
            'btts' => 0.67,          // 24/36 = 67% ✅
            'winner' => 0.59,        // 26/44 = 59%
            'exactScore' => 0.12,    // 3/26 = 12% ❌
        ],

        'C' => [
            'doubleChance' => 0.88,  // 36/41 = 88% ✅✅
            'winner' => 0.68,        // 25/37 = 68% ✅
            'btts' => 0.66,          // 25/38 = 66% ✅
            'overUnder' => 0.64,     // 25/39 = 64% ✅
            'exactScore' => 0.20,    // Estimation
        ],
    ],

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * SEUILS DE CONFIANCE
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     */
    'thresholds' => [
        // Règle spéciale Source C
        'sourceC_doubleChance_high' => 85,          // Seuil activation règle (85%+)
        'sourceC_doubleChance_reliability' => 94,   // Fiabilité historique de la règle
        
        // Règle spéciale Source B
        'sourceB_btts_priority' => 60,              // Seuil BTTS
        'sourceB_btts_reliability' => 75,           // Fiabilité historique BTTS
        'sourceB_overUnder_priority' => 65,         // Seuil O/U
        'sourceB_overUnder_reliability' => 68,      // Fiabilité historique O/U
        
        // Seuils généraux
        'high_confidence' => 70,
        'medium_confidence' => 60,
        'low_confidence' => 50,
    ],

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * POIDS DES BONUS
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     */
    'bonuses' => [
        'sourceC_doubleChance_boost' => 10,  // +10 pts si règle activée
        'sourceB_btts_boost' => 3,           // Poids x3 pour Source B sur BTTS
        'sourceA_overUnder_penalty' => 0.25, // Poids divisé par 4 sur O/U
    ],

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * TAUX DE RÉUSSITE PAR TYPE DE CONSENSUS
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     */
    'consensus_performance' => [
        'TOTAL' => 0.60,      // 60% - Moins bon que prévu
        'MAJORITÉ' => 0.65,   // 65% - Meilleur que TOTAL !
        'CONFLIT' => 0.45,    // 45% - À éviter
    ],

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * TAUX DE RÉUSSITE PAR MARCHÉ
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     */
    'market_performance' => [
        'doubleChance' => 0.87,  // 87% ✅✅ EXCELLENT
        'winner' => 0.62,        // 62% ✅ Bon
        'btts' => 0.63,          // 63% ✅ Bon
        'overUnder' => 0.59,     // 59% ⚖️ Moyen
        'exactScore' => 0.12,    // 12% ❌ TRÈS MAUVAIS
    ],

    /**
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * RÈGLES MÉTIER
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     */
    'rules' => [
        // Score exact : seuil minimum de confiance
        'exactScore_min_confidence' => 15,
        
        // Marchés à exclure si niveau < 4
        'excluded_markets' => ['exactScore'],
        
        // Confiance Source A sur Over/Under (forcée à 50%)
        'sourceA_overUnder_fixed_confidence' => 50,
    ],

];