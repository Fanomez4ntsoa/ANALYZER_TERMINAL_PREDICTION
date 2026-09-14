<?php

return [

    'key' => env('API_FOOTBALL_KEY'),
    'base_url' => env('API_FOOTBALL_BASE_URL', 'https://v3.football.api-sports.io'),

    // Saison par défaut (auto-détectée si non définie)
    'default_season' => env('API_FOOTBALL_SEASON', (int) date('Y')),

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
        113,  // Allsvenskan (Suède) — INACTIVE jusqu'en aout 2026 (debut saison)
        119,  // Superligaen (Danemark) — INACTIVE jusqu'en aout 2026 (debut saison)
        103,  // Eliteserien (Norvège) — INACTIVE jusqu'en aout 2026 (debut saison)
    ],

    // Ligues temporairement desactivees (pas assez de journees jouees < 10)
    // Filtrees du pipeline mais gardees en config pour reactivation simple.
    // A reactiver en aout 2026 quand la saison sera bien lancee.
    'inactive_leagues' => [
        113, // Allsvenskan
        119, // Superligaen
        103, // Eliteserien
    ],

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
