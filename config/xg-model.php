<?php

return [

    // Deux corrections issues du backtest de calibration (run #2, 13/09/2026).
    // Les valeurs par défaut sont celles de production ; `backtest:run` peut
    // rétablir l'ancien comportement (--legacy-home, --no-anchor) pour mesurer
    // chaque correction séparément.

    // Ancien comportement : facteur domicile appliqué après la fusion des signaux,
    // donc aussi au signal marché qui contient déjà l'avantage du terrain
    // (+4,6 à +5,6 points sur la victoire à domicile dans les dix divisions majeures).
    // false = le facteur ne s'applique qu'aux signaux xg_proxy et comparison,
    // avant la fusion ; en mode marché seul il ne s'applique plus du tout.
    'legacy_home_advantage_after_fusion' => false,

    // Sans cotes Over/Under, rien ne contraint la somme des λ et la recherche sur
    // grille dérive vers le bas, d'autant plus que le championnat marque.
    // true = le total est ancré sur la moyenne de buts du championnat, calculée
    // depuis historical_matches sur les saisons de travail uniquement.
    'anchor_total_on_league_average' => true,

];
