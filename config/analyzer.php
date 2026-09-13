<?php

return [
    'source_reliability' => [
        'A' => [
            'winner' => 0.75,
            'overUnder' => 0.25,
            'btts' => 0.25,
            'doubleChance' => 0.875,
            'exactScore' => 0.00,
        ],
        'B' => [
            'winner' => 0.75,
            'overUnder' => 0.625,
            'btts' => 0.75,
            'doubleChance' => 0.875,
            'exactScore' => 0.25,
        ],
        'C' => [
            'winner' => 0.50,
            'overUnder' => 0.50,
            'btts' => 0.50,
            'doubleChance' => 0.94,
            'exactScore' => 0.50,
        ],
    ],
    
    'markets' => ['winner', 'overUnder', 'btts', 'doubleChance', 'exactScore'],

    // Hiérarchie des sources :
    // D (modèle maison) → principal | E (API-Football) → secondaire | A/B/C → optionnels
    // Les fiabilités sont dans config/reliability.php
    
    'value_thresholds' => [
        'min_edge' => 5.0,      // 5% minimum pour avoir de la value
        'max_edge' => 30.0,     // 30% max avant d'être suspect
        'kelly_fraction' => 0.25, // 1/4 Kelly (safe)
    ],
];