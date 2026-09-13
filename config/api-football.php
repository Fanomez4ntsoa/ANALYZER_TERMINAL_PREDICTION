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
        'h2h'        => 1440,    // 24h — confrontations directes
        'statistics'  => 360,    // 6h — stats équipe
        'injuries'   => 120,     // 2h — blessures
        'predictions' => 360,   // 6h — prédictions API-Football
        'lineups'    => 60,      // 1h — compositions
        'standings'  => 720,     // 12h — classements
        'odds'       => 120,     // 2h — cotes (stables avant kickoff)
    ],

    // Bookmaker prioritaire pour /odds (1 call ciblé = payload réduit).
    // 8 = Bet365 (le plus complet : 86 marchés sur tests). Fallback automatique sur tous bookmakers.
    'preferred_bookmaker' => env('API_FOOTBALL_PREFERRED_BOOKMAKER', 8),

    // Limites API (plan gratuit : 100 req/jour)
    'rate_limit' => [
        'requests_per_minute' => 10,
    ],

];
