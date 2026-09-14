<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relevé de cotes du bookmaker du CLV. Seuls les relevés `reliable` sont des
 * observations : sans cache, cote datée (quoted_at) de moins de
 * pipeline.odds_snapshot.max_quote_age_minutes. Les relevés antérieurs au
 * 14/09/2026 sont non fiables et ne servent pas au test de mouvement de ligne.
 */
class OddsMovement extends Model
{
    protected $fillable = [
        'match_id',
        'bookmaker',
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
        'snapshot_at',
        'quoted_at',
        'reliable',
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
        'snapshot_at' => 'datetime',
        'quoted_at' => 'datetime',
        'reliable' => 'boolean',
    ];

    public function scopeReliable($query)
    {
        return $query->where('reliable', true);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
