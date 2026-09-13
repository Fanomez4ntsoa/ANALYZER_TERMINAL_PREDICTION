<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestFdRun extends Model
{
    protected $table = 'backtest_fd_runs';

    protected $fillable = [
        'label', 'sample', 'input_bookmaker', 'seasons', 'divisions', 'config',
        'matches_loaded', 'matches_evaluated', 'matches_by_season',
        'exclusions', 'results', 'warnings', 'export_path',
        'status', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'seasons' => 'array',
        'divisions' => 'array',
        'config' => 'array',
        'matches_by_season' => 'array',
        'exclusions' => 'array',
        'results' => 'array',
        'warnings' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function predictions(): HasMany
    {
        return $this->hasMany(BacktestFdPrediction::class, 'run_id');
    }
}
