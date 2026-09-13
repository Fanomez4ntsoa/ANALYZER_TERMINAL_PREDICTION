<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyCombo extends Model
{
    protected $fillable = [
        'date', 'rank', 'picks', 'match_count',
        'total_odds', 'combo_score', 'avg_confidence', 'logic',
        'won', 'profit',
    ];

    protected $casts = [
        'date' => 'date',
        'picks' => 'array',
        'won' => 'boolean',
    ];
}
