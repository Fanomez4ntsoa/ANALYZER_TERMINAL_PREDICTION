<?php

namespace App\Support\Terminal;

use App\Models\Prediction;

/**
 * Marchés d'ajustement et marchés dérivés (docs/design-system.md, principe 3).
 *
 * En marché seul, les λ sont estimés sur les cotes 1X2 et O/U 2.5 ; la Double
 * Chance en découle. Sur ces marchés, le modèle recalcule son point de départ :
 * l'écart est nul (O/U 2.5) ou un résidu d'ajustement (1X2, DC), jamais une
 * information. Seuls les marchés dérivés portent un écart propre au modèle.
 *
 * Un marché non classé lève une exception : un nouveau marché doit être rangé
 * explicitement, jamais coloré par défaut.
 */
final class MarketNature
{
    public const ADJUSTMENT = 'adjustment';
    public const DERIVED = 'derived';

    private const MAP = [
        Prediction::MARKET_WINNER => self::ADJUSTMENT,
        Prediction::MARKET_DOUBLE_CHANCE => self::ADJUSTMENT,
        Prediction::MARKET_OVER_UNDER_25 => self::ADJUSTMENT,
        Prediction::MARKET_BTTS => self::DERIVED,
    ];

    public static function of(string $market): string
    {
        return self::MAP[$market]
            ?? throw new \InvalidArgumentException("Marché non classé (ajustement ou dérivé) : {$market}");
    }

    public static function isDerived(string $market): bool
    {
        return self::of($market) === self::DERIVED;
    }

    public static function label(string $market): string
    {
        return self::isDerived($market) ? 'Dérivé' : 'Ajust.';
    }

    /**
     * Couleur de l'écart : 'pos', 'neg' ou null. Null pour tout marché
     * d'ajustement et pour un écart arrondi à 0,0.
     */
    public static function edgeTone(string $market, ?float $edge): ?string
    {
        if (!self::isDerived($market)) {
            return null;
        }

        return match (Fmt::sign($edge)) {
            1 => 'pos',
            -1 => 'neg',
            default => null,
        };
    }
}
