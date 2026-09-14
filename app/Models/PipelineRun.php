<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineRun extends Model
{
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const INCOMPLETE = 'incomplete';
    public const FAILED = 'failed';

    protected $fillable = [
        'run_date',
        'status',
        'started_at',
        'finished_at',
        'steps',
        'fetch_summary',
    ];

    protected $casts = [
        'run_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'steps' => 'array',
        'fetch_summary' => 'array',
    ];

    public function scopeSucceeded($query)
    {
        return $query->where('status', self::SUCCESS);
    }
}
