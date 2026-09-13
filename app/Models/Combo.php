<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Combo extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'name',
        'bets',
        'total_odds',
        'confidence',
        'priority',
        'logic',
        'validated',
    ];

    protected $casts = [
        'bets' => 'array',
        'total_odds' => 'decimal:2',
        'validated' => 'boolean',
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RELATIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Un combiné appartient à un match
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id', 'id');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Nombre de paris dans le combiné
     */
    public function getBetsCountAttribute(): int
    {
        return count($this->bets ?? []);
    }

    /**
     * Liste des paris formatée
     */
    public function getBetsFormattedAttribute(): string
    {
        return implode(' + ', $this->bets ?? []);
    }

    /**
     * Couleur selon la priorité
     */
    public function getPriorityColorAttribute(): string
    {
        $colors = [
            'MAX' => 'bg-red-500',
            'HIGH' => 'bg-orange-500',
            'MEDIUM' => 'bg-yellow-500',
        ];

        return $colors[$this->priority] ?? 'bg-gray-500';
    }

    /**
     * Statut de validation
     */
    public function getValidationStatusAttribute(): string
    {
        if ($this->validated === null) {
            return 'pending';
        }
        return $this->validated ? 'won' : 'lost';
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SCOPES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Combinés MAX priorité
     */
    public function scopeMaxPriority($query)
    {
        return $query->where('priority', 'MAX');
    }

    /**
     * Combinés validés (gagnés)
     */
    public function scopeWon($query)
    {
        return $query->where('validated', true);
    }

    /**
     * Combinés perdus
     */
    public function scopeLost($query)
    {
        return $query->where('validated', false);
    }
}
