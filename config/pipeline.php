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

];
