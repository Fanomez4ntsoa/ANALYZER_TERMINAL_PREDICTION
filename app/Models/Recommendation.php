<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'market',
        'bet',
        'confidence',
        'confidence_layer1',
        'score',
        'level',
        'odds',
        'consensus_type',
        'consensus_agreement',
        'predictions',
        'special_rule',
        'special_rule_reason',
        'logic',
        'warnings',
        'coherent',
        'validated',
    ];

    protected $casts = [
        'odds' => 'decimal:2',
        'predictions' => 'array',
        'warnings' => 'array',
        'coherent' => 'boolean',
        'validated' => 'boolean',
        'value_analysis' => 'array',
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RELATIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Une recommandation appartient à un match
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id', 'id');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Nom du marché formaté
     */
    public function getMarketNameAttribute(): string
    {
        $names = [
            'winner' => '1X2',
            'overUnder' => 'Over/Under 2.5',
            'btts' => 'BTTS',
            'doubleChance' => 'Double Chance',
            'exactScore' => 'Score Exact',
            'htft' => 'Mi-temps/Fin',
        ];

        return $names[$this->market] ?? $this->market;
    }

    /**
     * Nom court du marché
     */
    public function getMarketShortAttribute(): string
    {
        $names = [
            'winner' => '1X2',
            'overUnder' => 'O/U',
            'btts' => 'BTTS',
            'doubleChance' => 'DC',
            'exactScore' => 'Score',
            'htft' => 'HT/FT',
        ];

        return $names[$this->market] ?? $this->market;
    }

    /**
     * Label du niveau de priorité
     */
    public function getLevelLabelAttribute(): string
    {
        $labels = [
            1 => 'PRIORITÉ 1 - TRÈS SÛR',
            2 => 'PRIORITÉ 2 - SÛR',
            3 => 'PRIORITÉ 3 - MODÉRÉ',
            4 => 'VALUE BET',
        ];

        return $labels[$this->level] ?? 'INCONNU';
    }

    /**
     * Couleur CSS selon le niveau
     */
    public function getLevelColorAttribute(): string
    {
        $colors = [
            1 => 'bg-green-500',
            2 => 'bg-green-400',
            3 => 'bg-blue-400',
            4 => 'bg-yellow-400',
        ];

        return $colors[$this->level] ?? 'bg-gray-400';
    }

    /**
     * Delta entre Layer 1 et Layer 2
     */
    public function getConfidenceDeltaAttribute(): ?int
    {
        if ($this->confidence_layer1 === null) {
            return null;
        }
        return $this->confidence - $this->confidence_layer1;
    }

    /**
     * A une règle spéciale ?
     */
    public function getHasSpecialRuleAttribute(): bool
    {
        return !empty($this->special_rule);
    }

    /**
     * Statut de validation formaté
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
     * Recommandations prioritaires (niveau 1-2)
     */
    public function scopePriority($query)
    {
        return $query->whereIn('level', [1, 2]);
    }

    /**
     * Recommandations par marché
     */
    public function scopeByMarket($query, string $market)
    {
        return $query->where('market', $market);
    }

    /**
     * Recommandations validées (gagnées)
     */
    public function scopeWon($query)
    {
        return $query->where('validated', true);
    }

    /**
     * Recommandations perdues
     */
    public function scopeLost($query)
    {
        return $query->where('validated', false);
    }

    /**
     * Recommandations non validées
     */
    public function scopePending($query)
    {
        return $query->whereNull('validated');
    }

    /**
     * Recommandations avec consensus total
     */
    public function scopeConsensusTotal($query)
    {
        return $query->where('consensus_type', 'TOTAL');
    }

    /**
     * Par niveau minimum de confiance
     */
    public function scopeMinConfidence($query, int $confidence)
    {
        return $query->where('confidence', '>=', $confidence);
    }
}
