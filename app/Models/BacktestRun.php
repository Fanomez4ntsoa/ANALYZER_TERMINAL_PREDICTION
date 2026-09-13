<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestRun extends Model
{
    protected $fillable = [
        'config', 'date_from', 'date_to', 'staking_strategy',
        'bankroll', 'unit_stake',
        'total_matches', 'total_predictions', 'wins', 'losses',
        'win_rate', 'roi', 'yield_pct', 'max_drawdown', 'brier_score', 'final_bankroll',
        'by_league', 'by_market', 'by_confidence', 'by_consensus', 'by_odds_range',
        'calibration', 'bankroll_curve',
        'status',
    ];

    protected $casts = [
        'config' => 'array',
        'date_from' => 'date',
        'date_to' => 'date',
        'by_league' => 'array',
        'by_market' => 'array',
        'by_confidence' => 'array',
        'by_consensus' => 'array',
        'by_odds_range' => 'array',
        'calibration' => 'array',
        'bankroll_curve' => 'array',
    ];

    public function predictions(): HasMany
    {
        return $this->hasMany(BacktestPrediction::class, 'run_id');
    }
}
