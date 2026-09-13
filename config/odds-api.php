<?php

return [

    'key' => env('ODDS_API_KEY'),
    'base_url' => env('ODDS_API_BASE_URL', 'https://api.the-odds-api.com/v4'),

    // Région des bookmakers (eu = bookmakers européens)
    'regions' => env('ODDS_API_REGIONS', 'eu'),

    // Format des cotes
    'odds_format' => 'decimal',

    // Marchés "featured" disponibles sur l'endpoint /sports/{sport}/odds (1 call par ligue).
    // The Odds API limite cet endpoint à : h2h, spreads, totals, outrights.
    'default_markets' => ['h2h', 'totals'],

    // Marchés "extras" disponibles UNIQUEMENT sur /sports/{sport}/events/{eventId}/odds
    // (1 call par événement → plus coûteux mais nécessaire pour BTTS / DC / alt totals).
    'extra_markets' => ['alternate_totals', 'btts', 'double_chance'],

    // Activer la récupération des extras pour chaque événement (consomme du quota).
    'fetch_extra_markets' => env('ODDS_API_FETCH_EXTRA', true),

    // Mapping : league ID API-Football → sport key The Odds API
    'league_mapping' => [
        // Top 5 européens
        61  => 'soccer_france_ligue_one',
        39  => 'soccer_epl',
        140 => 'soccer_spain_la_liga',
        135 => 'soccer_italy_serie_a',
        78  => 'soccer_germany_bundesliga',

        // Coupes européennes
        2   => 'soccer_uefa_champs_league',
        3   => 'soccer_uefa_europa_league',

        // Autres ligues majeures
        88  => 'soccer_netherlands_eredivisie',
        94  => 'soccer_portugal_primeira_liga',
        144 => 'soccer_belgium_first_div',
        203 => 'soccer_turkey_super_league',

        // Secondes divisions
        40  => 'soccer_efl_champ',
        62  => 'soccer_france_ligue_two',
        136 => 'soccer_italy_serie_b',

        // Europe de l'Est et nordiques
        // 271 (Serbie) absent de The Odds API — cotes manuelles uniquement
        106 => 'soccer_poland_ekstraklasa',
        197 => 'soccer_greece_super_league',
        113 => 'soccer_sweden_allsvenskan',
        119 => 'soccer_denmark_superliga',
        103 => 'soccer_norway_eliteserien',
    ],

    // Cache TTL en minutes
    'cache_ttl' => [
        'odds'   => 120,    // 2h — les cotes bougent peu
        'events' => 360,    // 6h — liste des événements (gratuit mais on cache quand même)
        'sports' => 1440,   // 24h — liste des sports (quasi statique)
    ],

    // Gestion du quota (plan free = 500 req/mois)
    'quota' => [
        'monthly_limit' => 500,
        'alert_threshold' => 400,   // Alerte à 80% du quota
    ],

];
