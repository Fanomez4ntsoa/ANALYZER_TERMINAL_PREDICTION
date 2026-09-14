<?php

if (!function_exists('displayDate')) {
    /**
     * Convertir une date UTC vers la timezone d'affichage.
     * La DB stocke en UTC. L'affichage est en config('app.display_timezone').
     */
    function displayDate($date, string $format = 'd/m/Y H:i'): string
    {
        if (!$date) return '-';
        $tz = config('app.display_timezone');
        return \Carbon\Carbon::parse($date)->setTimezone($tz)->format($format);
    }
}

if (!function_exists('displayTime')) {
    /**
     * Raccourci pour afficher seulement l'heure.
     */
    function displayTime($date): string
    {
        return displayDate($date, 'H:i');
    }
}
