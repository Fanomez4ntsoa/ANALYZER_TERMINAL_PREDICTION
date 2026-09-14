<?php

return [

    // Créneau horaire des matchs importés par pipeline:run (heures UTC, bornes
    // incluses). 0 et 23 = aucun filtre. Ignoré avec --all.
    'match_start_hour' => (int) env('PIPELINE_MATCH_START_HOUR', 0),
    'match_end_hour' => (int) env('PIPELINE_MATCH_END_HOUR', 23),

];
