<?php

/*
 * Journal des sélections (prediction_log) : mesure du modèle sur matchs réels.
 * Voir docs/decisions.md, 15/09/2026.
 */
return [

    // Effectif minimal, en MATCHS clôturés par marché (jamais en lignes : les issues
    // d'un même match ne sont pas indépendantes). En dessous, log:report montre les
    // chiffres mais dit qu'ils ne permettent aucune conclusion. Au-dessus, il ne
    // conclut pas davantage : seuil nécessaire, pas suffisant.
    'min_matches' => 200,

    // Tranches de probabilité annoncée, comme le backtest (5 points)
    'bin_width' => 0.05,

    // Familles de marchés, dans l'ordre du rapport
    'groups' => [
        'adjustment' => [
            'label' => "Marchés d'ajustement",
            'note' => 'les λ sont ajustés sur ces cotes : contrôle de cohérence, pas un test indépendant',
            'markets' => ['winner', 'overUnder25'],
        ],
        'recombination' => [
            'label' => 'Recombinaison du 1X2',
            'note' => 'chaque probabilité est le complément d\'une issue du 1X2 : répète le contrôle 1X2',
            'markets' => ['doubleChance'],
        ],
        'derived' => [
            'label' => 'Marchés dérivés',
            'note' => 'non utilisés pour ajuster les λ : test indépendant de la forme de la loi jointe',
            'markets' => ['btts'],
        ],
    ],

    // Signaux hors marché du modèle complet. Sans aucun d'eux, le modèle complet
    // donne exactement le marché seul : ces lignes sont exclues de sa comparaison.
    'full_model_extra_signals' => ['comparison', 'injuries'],

];
