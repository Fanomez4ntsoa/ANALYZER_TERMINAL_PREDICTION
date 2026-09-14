<?php

namespace App\Support\Terminal;

use App\Models\Prediction;

/**
 * Libellés courts des marchés et des issues, et ordre d'affichage fixe.
 * L'ordre suit le catalogue des marchés, jamais une qualité (principe 1).
 */
final class MarketLabel
{
    public const ORDER = [
        Prediction::MARKET_WINNER => ['1', 'X', '2'],
        Prediction::MARKET_DOUBLE_CHANCE => ['1X', 'X2', '12'],
        Prediction::MARKET_OVER_UNDER_25 => ['Over', 'Under'],
        Prediction::MARKET_BTTS => ['Yes', 'No'],
    ];

    private const MARKETS = [
        Prediction::MARKET_WINNER => '1X2',
        Prediction::MARKET_DOUBLE_CHANCE => 'DC',
        Prediction::MARKET_OVER_UNDER_25 => 'O/U 2.5',
        Prediction::MARKET_BTTS => 'BTTS',
    ];

    private const OUTCOMES = [
        'Yes' => 'Oui',
        'No' => 'Non',
    ];

    public static function market(string $market): string
    {
        return self::MARKETS[$market] ?? $market;
    }

    /** « 1X2 · X », « BTTS · Oui ». */
    public static function tag(string $market, string $outcome): string
    {
        return self::market($market) . ' · ' . (self::OUTCOMES[$outcome] ?? $outcome);
    }

    /** Clé de tri : rang du marché puis de l'issue dans le catalogue. */
    public static function sortKey(string $market, string $outcome): int
    {
        $marketRank = array_search($market, array_keys(self::ORDER), true);
        $outcomeRank = array_search($outcome, self::ORDER[$market] ?? [], true);

        return (($marketRank === false ? 99 : $marketRank) * 100) + ($outcomeRank === false ? 99 : $outcomeRank);
    }
}
