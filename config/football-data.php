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

    // Run de référence du panneau de calibration de l'interface : le run #8, seul run
    // dont la configuration est celle de la production (entrée Bet365 ouverture,
    // mode marché seul, Dixon-Coles à ρ unique). Jamais « le dernier run » : le #9,
    // plus récent, prend Pinnacle en entrée. Lu sur la population Top 5 et les saisons
    // 2223-2324 (2122 n'a pas de saison antérieure pour estimer ρ).
    'reference_run' => [
        'id' => (int) env('BACKTEST_REFERENCE_RUN', 8),
        'population' => 'top5',
        'seasons' => ['2223', '2324'],
    ],

    // Populations de lecture du backtest. Jamais d'agrégat sur les 22 divisions
    // confondues : sur 7 800 matchs par saison, les cinq grands championnats n'en
    // font que 1 750 et un agrégat global décrit surtout la quatrième division
    // anglaise. « other » = toutes les divisions absentes des deux premières.
    'populations' => [
        'top5' => ['label' => 'Top 5', 'divisions' => ['E0', 'D1', 'I1', 'SP1', 'F1']],
        'second' => ['label' => 'Deuxièmes divisions', 'divisions' => ['E1', 'D2', 'I2', 'SP2', 'F2']],
        'other' => ['label' => 'Inférieures et autres', 'divisions' => null],
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
