<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestFdPrediction extends Model
{
    protected $table = 'backtest_fd_predictions';

    public $timestamps = false;

    protected $fillable = [
        'run_id', 'historical_match_id', 'season', 'div',
        'family', 'market', 'outcome',
        'model_probability', 'input_open_fair', 'pinnacle_close_fair', 'observed',
    ];

    protected $casts = [
        'model_probability' => 'float',
        'input_open_fair' => 'float',
        'pinnacle_close_fair' => 'float',
        'observed' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestFdRun::class, 'run_id');
    }

    public function historicalMatch(): BelongsTo
    {
        return $this->belongsTo(HistoricalMatch::class, 'historical_match_id');
    }
}
