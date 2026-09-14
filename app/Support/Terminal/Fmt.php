<?php

namespace App\Support\Terminal;

/**
 * Format français de l'interface : virgule décimale, signe moins U+2212.
 * Probabilités en % à une décimale, cotes à deux décimales, écarts en points
 * signés à une décimale. Une valeur absente rend une chaîne vide : aucune
 * estimation n'est jamais affichée à sa place.
 */
final class Fmt
{
    public const MINUS = "\u{2212}";

    /** Probabilité 0..1 → « 41,2 ». */
    public static function percent(?float $probability): string
    {
        return $probability === null ? '' : self::number($probability * 100, 1);
    }

    /** Cote décimale → « 2,38 ». */
    public static function odds(?float $odds): string
    {
        return $odds === null ? '' : self::number($odds, 2);
    }

    /** Écart 0..1 → points signés « +2,1 », « −2,1 », « 0,0 » (jamais « −0,0 »). */
    public static function points(?float $edge): string
    {
        if ($edge === null) {
            return '';
        }

        $rounded = round($edge * 100, 1);
        if ($rounded == 0.0) {
            return self::number(0.0, 1);
        }

        return ($rounded > 0 ? '+' : '') . self::number($rounded, 1);
    }

    /** Signe d'un écart arrondi à une décimale de point : 1, −1 ou 0. */
    public static function sign(?float $edge): int
    {
        if ($edge === null) {
            return 0;
        }

        $rounded = round($edge * 100, 1);

        return $rounded > 0 ? 1 : ($rounded < 0 ? -1 : 0);
    }

    public static function number(float $value, int $decimals, string $thousands = "\u{202F}"): string
    {
        $rounded = round($value, $decimals);

        // round(-0.04, 1) vaut -0.0, qui n'est pas < 0 : jamais de « −0,0 »
        return ($rounded < 0 ? self::MINUS : '') . number_format(abs($rounded), $decimals, ',', $thousands);
    }
}
