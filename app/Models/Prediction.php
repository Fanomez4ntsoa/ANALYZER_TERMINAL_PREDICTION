<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une prédiction = la probabilité du modèle pour une issue d'un marché,
 * mise en regard de la cote d'un bookmaker unique.
 */
class Prediction extends Model
{
    public const MARKET_WINNER = 'winner';
    public const MARKET_DOUBLE_CHANCE = 'doubleChance';
    public const MARKET_OVER_UNDER_25 = 'overUnder25';
    public const MARKET_BTTS = 'btts';

    protected $fillable = [
        'match_id',
        'market',
        'outcome',
        'model_probability',
        'odds',
        'implied_probability',
        'fair_probability',
        'edge',
        'bookmaker',
        'odds_taken_at',
        'computed_at',
    ];

    protected $casts = [
        'model_probability' => 'float',
        'odds' => 'float',
        'implied_probability' => 'float',
        'fair_probability' => 'float',
        'edge' => 'float',
        'odds_taken_at' => 'datetime',
        'computed_at' => 'datetime',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id', 'id');
    }

    public function getMarketLabelAttribute(): string
    {
        return match ($this->market) {
            self::MARKET_WINNER => '1X2',
            self::MARKET_DOUBLE_CHANCE => 'Double Chance',
            self::MARKET_OVER_UNDER_25 => 'Over/Under 2.5',
            self::MARKET_BTTS => 'BTTS',
            default => $this->market,
        };
    }
}
