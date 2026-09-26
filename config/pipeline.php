<?php

return [

    // Créneau horaire des matchs importés par pipeline:run (heures UTC, bornes
    // incluses). 0 et 23 = aucun filtre. Ignoré avec --all.
    'match_start_hour' => (int) env('PIPELINE_MATCH_START_HOUR', 0),
    'match_end_hour' => (int) env('PIPELINE_MATCH_END_HOUR', 23),

    // Heure du passage quotidien pipeline:daily (HH:MM) et son fuseau. 10:00 UTC
    // précède le créneau 12h-21h UTC : aucun match du jour n'a commencé.
    'schedule_time' => env('PIPELINE_SCHEDULE_TIME', '10:00'),
    'schedule_timezone' => env('PIPELINE_SCHEDULE_TIMEZONE', 'UTC'),

    // Clôture automatique (market:track closing puis close, toutes les 5 minutes).
    'closing' => [
        // Snapshot pris quand le coup d'envoi tombe dans les N prochaines minutes.
        // La clôture retenue est le dernier snapshot de cette fenêtre.
        'window_minutes' => (int) env('PIPELINE_CLOSING_WINDOW_MINUTES', 10),

        // Championnats clôturés (id API-Football). Top 5 par défaut : le quota de
        // 500 crédits/mois couvre ~260 crédits de clôture, pas tous les championnats
        // (~600 crédits estimés sur avril-mai 2026).
        // Par défaut, le périmètre du relevé des cotes : un CLV sans cotes n'a pas de sens.
        'leagues' => array_map('intval', explode(',', env('PIPELINE_CLOSING_LEAGUES', env('API_FOOTBALL_ODDS_LEAGUES', '39,78,135,140,61')))),

        // Quota The Odds API restant sous lequel plus aucune clôture n'est relevée.
        'quota_reserve' => (int) env('PIPELINE_CLOSING_QUOTA_RESERVE', 50),
    ],

    // Relevés de cotes du CLV (market:track snapshot et closing) : toujours sans
    // cache. Un relevé dont la cote du bookmaker (last_update) a plus de N minutes
    // est refusé : c'est une réponse recyclée, pas une observation. Mesuré le
    // 14/09/2026 sur 1 003 cotes fraîches : médiane 0,4 min, p90 1,7 min, max 5,7.
    'odds_snapshot' => [
        'max_quote_age_minutes' => (int) env('PIPELINE_MAX_QUOTE_AGE_MINUTES', 10),
    ],

    // Sauvegarde quotidienne de la base (db:backup, première étape de pipeline:daily).
    // Le projet n'en avait aucune quand la base a été vidée le 15/09/2026.
    // Un fichier horodaté par passage, jamais écrasé ; après une sauvegarde réussie,
    // seules les sauvegardes des `keep_days` derniers jours distincts sont gardées.
    'backup' => [
        'connection' => env('PIPELINE_BACKUP_CONNECTION', env('DB_CONNECTION', 'mariadb')),
        'path' => storage_path('app/private/backups'),
        'keep_days' => (int) env('PIPELINE_BACKUP_KEEP_DAYS', 7),
        'dump_binary' => env('PIPELINE_BACKUP_DUMP_BINARY', 'mysqldump'),
        'client_binary' => env('PIPELINE_BACKUP_CLIENT_BINARY', 'mysql'),
        'timeout_seconds' => (int) env('PIPELINE_BACKUP_TIMEOUT', 900),

        // Jamais de suppression d'une sauvegarde si la nouvelle fait moins de cette
        // fraction de sa taille : une base qui rétrécit brutalement est une anomalie.
        'shrink_ratio' => (float) env('PIPELINE_BACKUP_SHRINK_RATIO', 0.5),

        // Tables comptées par db:backup --verify après restauration
        'verify_tables' => [
            'users', 'matches', 'advanced_data', 'predictions', 'prediction_log',
            'odds_movements', 'pipeline_runs', 'historical_matches',
            'backtest_fd_runs', 'backtest_fd_predictions',
            'recommendations', 'match_validations',
        ],
    ],

    // Rattrapage des scores (FetchMatchDataJob) et limite de clôture du journal.
    // L'offre gratuite n'accepte /fixtures?date= que de J-1 à J+1 : au-delà, un score
    // se récupère match par match (/fixtures?id=, 1 requête), après les cotes du jour
    // et avant le facultatif. Mesuré le 26/09/2026 : id= répond encore à 153 jours,
    // saison précédente comprise.
    'score_catchup' => [
        // Requêtes au plus par passage
        'max_requests' => (int) env('PIPELINE_SCORE_CATCHUP_MAX_REQUESTS', 20),

        // Au-delà, plus aucune requête : les lignes encore en attente sont déclarées
        // non clôturables (score_unavailable) par log:settle
        'window_days' => (int) env('PIPELINE_SCORE_CATCHUP_WINDOW_DAYS', 60),
    ],

];
