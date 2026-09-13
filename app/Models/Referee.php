<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referee extends Model
{
    protected $fillable = [
        'api_football_id',
        'name',
        'country',
        'games_officiated',
        'yellow_per_game',
        'red_per_game',
        'penalties_per_game',
        'fouls_per_game',
        'style',
    ];

    protected $casts = [
        'yellow_per_game' => 'decimal:2',
        'red_per_game' => 'decimal:2',
        'penalties_per_game' => 'decimal:2',
        'fouls_per_game' => 'decimal:2',
    ];

    /**
     * Déduire le style de l'arbitre depuis ses stats.
     */
    public function computeStyle(): string
    {
        $cardsPerGame = (float) $this->yellow_per_game + ((float) $this->red_per_game * 3);

        if ($cardsPerGame >= 5.0) return 'strict';
        if ($cardsPerGame <= 3.0) return 'lenient';
        return 'moderate';
    }
}
