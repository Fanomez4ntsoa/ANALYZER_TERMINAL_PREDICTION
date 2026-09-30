<?php

return [

    'key' => env('API_FOOTBALL_KEY'),
    'base_url' => env('API_FOOTBALL_BASE_URL', 'https://v3.football.api-sports.io'),

    // Ligues suivies — IDs API-Football
    'leagues' => [
        // Top 5 européens
        61,   // Ligue 1 (France)
        39,   // Premier League (Angleterre)
        140,  // La Liga (Espagne)
        135,  // Serie A (Italie)
        78,   // Bundesliga (Allemagne)

        // Coupes européennes
        2,    // Champions League
        3,    // Europa League
        848,  // Conference League

        // Autres ligues majeures
        88,   // Eredivisie (Pays-Bas)
        94,   // Primeira Liga (Portugal)
        144,  // Jupiler Pro League (Belgique)
        203,  // Süper Lig (Turquie)

        // Secondes divisions
        40,   // Championship (Angleterre D2)
        62,   // Ligue 2 (France)
        136,  // Serie B (Italie)

        // Europe de l'Est et nordiques
        271,  // Super Liga (Serbie) — pas de cotes auto via Odds API
        106,  // Ekstraklasa (Pologne)
        197,  // Super League (Grèce)
        113,  // Allsvenskan (Suède)
        119,  // Superligaen (Danemark)
        103,  // Eliteserien (Norvège)
    ],

    // Périmètre du relevé des cotes (id API-Football), sous-ensemble de `leagues`.
    // Top 5 par défaut : Premier League 39 (E0), Bundesliga 78 (D1), Serie A 135 (I1),
    // La Liga 140 (SP1), Ligue 1 61 (F1). Les autres ligues suivies gardent l'import
    // des matchs et des scores (1 requête par jour pour toutes), sans cotes.
    // Élargir coûte 1 requête de cote + 2 de facultatif par match (voir
    // docs/decisions.md, 14/09/2026).
    'odds_leagues' => array_map('intval', explode(',', env('API_FOOTBALL_ODDS_LEAGUES', '39,78,135,140,61'))),

    // Ligues suivies mais exclues de l'import (début de saison, trop peu de
    // journées jouées). Le pipeline et api-football:test --date les ignorent.
    // Vide depuis le 30/09/2026 : 113, 119 et 103, exclues depuis le printemps,
    // réactivées.
    'inactive_leagues' => [],

    // Cache TTL en minutes par type de donnée
    'cache_ttl' => [
        'fixtures'   => 60,      // 1h — matchs à venir
        'injuries'   => 120,     // 2h — blessures
        'predictions' => 360,   // 6h — prédictions API-Football
        // Les cotes par date ne sont pas mises en cache : pages lues d'un seul tenant
    ],

    // Bookmaker unique des cotes des prédictions (id API-Football). 8 = Bet365.
    // Aucun repli sur les autres bookmakers : s'il ne couvre pas le match, aucune cote n'est stockée.
    // Distinct du bookmaker du CLV (odds-api.clv_bookmaker, Pinnacle).
    'preferred_bookmaker' => env('API_FOOTBALL_PREFERRED_BOOKMAKER', 8),

    // Offre gratuite : 10 requêtes/minute (appels espacés en conséquence), 100/jour.
    'rate_limit' => [
        'requests_per_minute' => (int) env('API_FOOTBALL_REQUESTS_PER_MINUTE', 10),
    ],

    'budget' => [
        // Requêtes du jour gardées en réserve : sous ce seuil, les données
        // facultatives (prédictions, blessures) ne sont plus collectées.
        'optional_reserve' => (int) env('API_FOOTBALL_OPTIONAL_RESERVE', 10),
    ],

];
