<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FootballMatch extends Model
{
    use HasFactory;

    // Note: "match" est un mot réservé en PHP 8, donc on précise le nom de la table
    protected $table = 'matches';

    protected $fillable = [
        'react_id',
        'api_football_id',
        'odds_api_event_id',
        'odds_fetched_at',
        'home_team',
        'home_team_id',
        'away_team',
        'away_team_id',
        'match_date',
        'competition',
        'league_id',
        'season',
        'data_source',
        'odds_home',
        'odds_draw',
        'odds_away',
        'odds_over_2_5',
        'odds_under_2_5',
        'odds_over_1_5',
        'odds_under_1_5',
        'odds_over_3_5',
        'odds_under_3_5',
        'odds_over_4_5',
        'odds_under_4_5',
        'odds_over_2_0',
        'odds_under_2_0',
        'odds_over_2_25',
        'odds_under_2_25',
        'odds_btts_yes',
        'odds_btts_no',
        'odds_dc_1x',
        'odds_dc_12',
        'odds_dc_x2',
        'odds_home_over_0_5',
        'odds_home_under_0_5',
        'odds_home_over_1_5',
        'odds_home_under_1_5',
        'odds_away_over_0_5',
        'odds_away_under_0_5',
        'odds_away_over_1_5',
        'odds_away_under_1_5',
        'odds_at_pred_home',
        'odds_at_pred_draw',
        'odds_at_pred_away',
        'odds_at_pred_over',
        'odds_closing_home',
        'odds_closing_draw',
        'odds_closing_away',
        'odds_closing_over',
        'predicted_at',
        'score_home',
        'score_away',
        'completed',
        'post_kickoff_data',
        'global_confidence',
        'context',
        'layer1_score',
        'layer2_score',
        'convergence',
        'validated',
        'validated_at',
        'enriched_at',
    ];

    protected $casts = [
        'match_date' => 'datetime',
        'validated_at' => 'datetime',
        'enriched_at' => 'datetime',
        'odds_fetched_at' => 'datetime',
        'predicted_at' => 'datetime',
        'odds_home' => 'decimal:3',
        'odds_draw' => 'decimal:3',
        'odds_away' => 'decimal:3',
        'odds_over_2_5' => 'decimal:3',
        'odds_under_2_5' => 'decimal:3',
        'odds_over_1_5' => 'decimal:3',
        'odds_under_1_5' => 'decimal:3',
        'odds_over_3_5' => 'decimal:3',
        'odds_under_3_5' => 'decimal:3',
        'odds_over_4_5' => 'decimal:3',
        'odds_under_4_5' => 'decimal:3',
        'odds_over_2_0' => 'decimal:3',
        'odds_under_2_0' => 'decimal:3',
        'odds_over_2_25' => 'decimal:3',
        'odds_under_2_25' => 'decimal:3',
        'odds_btts_yes' => 'decimal:3',
        'odds_btts_no' => 'decimal:3',
        'odds_dc_1x' => 'decimal:3',
        'odds_dc_12' => 'decimal:3',
        'odds_dc_x2' => 'decimal:3',
        'odds_home_over_0_5' => 'decimal:3',
        'odds_home_under_0_5' => 'decimal:3',
        'odds_home_over_1_5' => 'decimal:3',
        'odds_home_under_1_5' => 'decimal:3',
        'odds_away_over_0_5' => 'decimal:3',
        'odds_away_under_0_5' => 'decimal:3',
        'odds_away_over_1_5' => 'decimal:3',
        'odds_away_under_1_5' => 'decimal:3',
        'completed' => 'boolean',
        'post_kickoff_data' => 'boolean',
        'validated' => 'boolean',
        'context' => 'array',
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RELATIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Un match a plusieurs prédictions (une par marché et issue)
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class, 'match_id', 'id');
    }

    /**
     * Un match a plusieurs snapshots de cotes
     */
    public function oddsMovements(): HasMany
    {
        return $this->hasMany(OddsMovement::class, 'match_id', 'id');
    }

    /**
     * Un match a une entrée de données avancées (API-Football normalisées)
     */
    public function advancedData(): HasOne
    {
        return $this->hasOne(AdvancedData::class, 'match_id', 'id');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS (Propriétés calculées)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Nom complet du match
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->home_team} vs {$this->away_team}";
    }

    /**
     * Résultat formaté
     */
    public function getResultAttribute(): ?string
    {
        if (!$this->completed) {
            return null;
        }
        return "{$this->score_home} - {$this->score_away}";
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // SCOPES (Filtres réutilisables)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Matchs admissibles dans une mesure : aucune donnée écrite après le coup
     * d'envoi (post_kickoff_data). Toute mesure sur `matches` passe par ce scope.
     */
    public function scopeMeasurable($query)
    {
        return $query->where('post_kickoff_data', false);
    }

    /**
     * Coup d'envoi passé : plus aucune donnée ne doit être écrite sur ce match,
     * hormis le score.
     */
    public function hasKickedOff(): bool
    {
        return $this->match_date !== null && $this->match_date->lte(now());
    }

    /**
     * Matchs validés uniquement
     */
    public function scopeValidated($query)
    {
        return $query->where('validated', true);
    }

    /**
     * Matchs non validés
     */
    public function scopeNotValidated($query)
    {
        return $query->where('validated', false);
    }

    /**
     * Matchs terminés
     */
    public function scopeCompleted($query)
    {
        return $query->where('completed', true);
    }

    /**
     * Matchs par compétition
     */
    public function scopeByCompetition($query, string $competition)
    {
        return $query->where('competition', $competition);
    }
}
