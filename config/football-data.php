<?php

return [

    // Source : https://www.football-data.co.uk — un zip par saison, un CSV par division.
    'base_url' => 'https://www.football-data.co.uk/mmz4281',

    // Répertoire de stockage des zips et CSV (disque local privé)
    'storage_path' => 'football-data',

    // Saisons de travail (réglages autorisés) et saisons réservées (jamais touchées par un réglage)
    'work_seasons' => ['2122', '2223', '2324'],
    'holdout_seasons' => ['2425', '2526'],

    // Liste blanche des colonnes lues. Les colonnes Max* et Avg* (maximum / moyenne
    // multi-bookmakers) sont volontairement absentes et ne doivent jamais être ajoutées.
    'columns' => [
        'Div' => 'div',
        'Date' => 'match_date',
        'Time' => 'kickoff_time',
        'HomeTeam' => 'home_team',
        'AwayTeam' => 'away_team',
        'FTHG' => 'fthg',
        'FTAG' => 'ftag',
        'FTR' => 'ftr',
        // Bet365 ouverture
        'B365H' => 'b365_open_home',
        'B365D' => 'b365_open_draw',
        'B365A' => 'b365_open_away',
        'B365>2.5' => 'b365_open_over25',
        'B365<2.5' => 'b365_open_under25',
        // Bet365 clôture
        'B365CH' => 'b365_close_home',
        'B365CD' => 'b365_close_draw',
        'B365CA' => 'b365_close_away',
        'B365C>2.5' => 'b365_close_over25',
        'B365C<2.5' => 'b365_close_under25',
        // Pinnacle ouverture
        'PSH' => 'ps_open_home',
        'PSD' => 'ps_open_draw',
        'PSA' => 'ps_open_away',
        'P>2.5' => 'ps_open_over25',
        'P<2.5' => 'ps_open_under25',
        // Pinnacle clôture
        'PSCH' => 'ps_close_home',
        'PSCD' => 'ps_close_draw',
        'PSCA' => 'ps_close_away',
        'PC>2.5' => 'ps_close_over25',
        'PC<2.5' => 'ps_close_under25',
    ],

    // Colonnes obligatoires : une ligne sans l'une d'elles est ignorée à l'import
    'required_columns' => ['Div', 'Date', 'HomeTeam', 'AwayTeam'],

    // Mapping Div → id de ligue API-Football (moyenne xG par ligue de XGModelService).
    // Non bloquant en mode marché seul : la moyenne de ligue n'y intervient pas.
    'league_ids' => [
        'E0' => 39, 'E1' => 40, 'E2' => 41, 'E3' => 42, 'EC' => 43,
        'SC0' => 179, 'SC1' => 180, 'SC2' => 183, 'SC3' => 184,
        'D1' => 78, 'D2' => 79,
        'I1' => 135, 'I2' => 136,
        'SP1' => 140, 'SP2' => 141,
        'F1' => 61, 'F2' => 62,
        'N1' => 88, 'B1' => 144, 'P1' => 94, 'T1' => 203, 'G1' => 197,
    ],

    'labels' => [
        'E0' => 'Premier League', 'E1' => 'Championship', 'E2' => 'League One', 'E3' => 'League Two', 'EC' => 'National League',
        'SC0' => 'Scottish Premiership', 'SC1' => 'Scottish Championship', 'SC2' => 'Scottish League One', 'SC3' => 'Scottish League Two',
        'D1' => 'Bundesliga', 'D2' => '2. Bundesliga',
        'I1' => 'Serie A', 'I2' => 'Serie B',
        'SP1' => 'La Liga', 'SP2' => 'Segunda División',
        'F1' => 'Ligue 1', 'F2' => 'Ligue 2',
        'N1' => 'Eredivisie', 'B1' => 'Jupiler Pro League', 'P1' => 'Primeira Liga', 'T1' => 'Süper Lig', 'G1' => 'Super League',
    ],

];
