<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OddsMovement extends Model
{
    protected $fillable = [
        'match_id',
        'odds_home',
        'odds_draw',
        'odds_away',
        'odds_over_2_5',
        'odds_under_2_5',
        'bookmaker_count',
        'move_home_pct',
        'move_draw_pct',
        'move_away_pct',
        'move_over_pct',
        'sharp_alert',
        'sharp_score',
        'snapshot_at',
    ];

    protected $casts = [
        'odds_home' => 'decimal:3',
        'odds_draw' => 'decimal:3',
        'odds_away' => 'decimal:3',
        'odds_over_2_5' => 'decimal:3',
        'odds_under_2_5' => 'decimal:3',
        'move_home_pct' => 'decimal:2',
        'move_draw_pct' => 'decimal:2',
        'move_away_pct' => 'decimal:2',
        'move_over_pct' => 'decimal:2',
        'sharp_alert' => 'boolean',
        'snapshot_at' => 'datetime',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
