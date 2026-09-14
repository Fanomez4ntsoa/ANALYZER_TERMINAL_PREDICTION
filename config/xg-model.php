<?php

return [

    // Corrections issues du backtest de calibration (13/09/2026). Les valeurs par
    // défaut sont celles de production ; `backtest:run` peut rétablir chaque ancien
    // comportement (--legacy-home, --legacy-share) ou activer l'ancrage (--anchor)
    // pour mesurer chaque changement séparément.

    // Ancien comportement : facteur domicile appliqué après la fusion des signaux,
    // donc aussi au signal marché qui contient déjà l'avantage du terrain
    // (+4,6 à +5,6 points sur la victoire à domicile, run #2).
    // false = le facteur ne s'applique qu'au signal comparison.
    'legacy_home_advantage_after_fusion' => false,

    // Ancien comportement : partage λh/λa trouvé par la grille 1X2 (à un total libre),
    // puis total recalé sur l'O/U en conservant ce partage. Le 1X2 dérive alors
    // (run #4 : 1,6 à 2,1 points de nul en moins que la cote d'entrée).
    // false = recalage conjoint : total fixé par l'O/U, puis partage recherché sur
    // le 1X2 à ce total.
    'legacy_constant_share_rescaling' => false,

    // Ancrage du total sur la moyenne de buts du championnat quand les cotes O/U
    // manquent. DÉSACTIVÉ : sans fuite, il dégrade le Brier du transfert dans le
    // Top 5, car un total constant par championnat efface la variation match par
    // match que la grille tire du 1X2 (runs #3 et #5, mesure hors échantillon).
    'anchor_total_on_league_average' => false,

    // Correction de Dixon-Coles sur les scores faibles (0-0, 1-0, 0-1, 1-1). ρ estimé
    // par maximum de vraisemblance sur les scores observés, par population, sur des
    // saisons strictement antérieures au match en backtest (DixonColesRho).
    // false = deux lois de Poisson indépendantes, comportement des runs #2 à #6.
    'dixon_coles_low_score_correction' => true,

    // Portée de l'estimation de ρ. 'global' : un ρ unique sur toutes les divisions
    // (défaut depuis le 13/09/2026, les trois populations ne se distinguant pas
    // statistiquement au run #7). 'population' : un ρ par population (run #7).
    'dixon_coles_rho_scope' => 'global',

];
