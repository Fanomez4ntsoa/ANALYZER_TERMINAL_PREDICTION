<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Match historique football-data.co.uk (table distincte de `matches`).
 */
class HistoricalMatch extends Model
{
    protected $fillable = [
        'season', 'div', 'league_id', 'match_date', 'kickoff_time',
        'home_team', 'away_team', 'fthg', 'ftag', 'ftr',
        'b365_open_home', 'b365_open_draw', 'b365_open_away', 'b365_open_over25', 'b365_open_under25',
        'b365_close_home', 'b365_close_draw', 'b365_close_away', 'b365_close_over25', 'b365_close_under25',
        'ps_open_home', 'ps_open_draw', 'ps_open_away', 'ps_open_over25', 'ps_open_under25',
        'ps_close_home', 'ps_close_draw', 'ps_close_away', 'ps_close_over25', 'ps_close_under25',
        'source_file', 'imported_at',
    ];

    protected $casts = [
        'match_date' => 'date',
        'imported_at' => 'datetime',
        'fthg' => 'integer',
        'ftag' => 'integer',
        'league_id' => 'integer',
    ];

    public function getLabelAttribute(): string
    {
        return "{$this->home_team} v {$this->away_team} ({$this->div} {$this->season})";
    }
}
