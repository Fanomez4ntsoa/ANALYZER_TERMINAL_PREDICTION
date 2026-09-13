<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestPrediction extends Model
{
    protected $fillable = [
        'run_id', 'api_fixture_id',
        'home_team', 'away_team', 'league', 'league_id', 'match_date',
        'score_home', 'score_away',
        'market', 'pick', 'confidence', 'odds', 'probability',
        'won', 'profit', 'bankroll_after',
    ];

    protected $casts = [
        'match_date' => 'datetime',
        'won' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'run_id');
    }
}
