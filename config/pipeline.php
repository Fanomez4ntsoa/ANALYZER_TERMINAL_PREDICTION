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
        'leagues' => array_map('intval', explode(',', env('PIPELINE_CLOSING_LEAGUES', '39,140,135,78,61'))),

        // Quota The Odds API restant sous lequel plus aucune clôture n'est relevée.
        'quota_reserve' => (int) env('PIPELINE_CLOSING_QUOTA_RESERVE', 50),
    ],

];
