<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvancedData extends Model
{
    use HasFactory;

    protected $table = 'advanced_data';

    protected $fillable = [
        'match_id',
        // Données d'entrée
        'tactical_data',
        'sofascore_data',
        'footystats_data',
        'fbref_data',
        'context_data',
        // Résultats d'analyse
        'dimensions',
        'tactical_insights',
        'original_recommendations',
        'enriched_recommendations',
        'detailed_explanation',
    ];

    protected $casts = [
        'tactical_data' => 'array',
        'sofascore_data' => 'array',
        'footystats_data' => 'array',
        'fbref_data' => 'array',
        'context_data' => 'array',
        'dimensions' => 'array',
        'tactical_insights' => 'array',
        'original_recommendations' => 'array',
        'enriched_recommendations' => 'array',
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RELATIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Les données avancées appartiennent à un match
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id', 'id');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS - Données tactiques
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function getHomeFormationAttribute(): ?string
    {
        return $this->tactical_data['home']['formation'] ?? null;
    }

    public function getAwayFormationAttribute(): ?string
    {
        return $this->tactical_data['away']['formation'] ?? null;
    }

    public function getHomeStyleAttribute(): ?string
    {
        return $this->tactical_data['home']['style'] ?? null;
    }

    public function getAwayStyleAttribute(): ?string
    {
        return $this->tactical_data['away']['style'] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS - Sofascore
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function getHomeFormAttribute(): ?array
    {
        return $this->sofascore_data['recentForm']['home'] ?? null;
    }

    public function getAwayFormAttribute(): ?array
    {
        return $this->sofascore_data['recentForm']['away'] ?? null;
    }

    public function getHomeInjuriesAttribute(): ?array
    {
        return $this->sofascore_data['injuries']['home'] ?? null;
    }

    public function getAwayInjuriesAttribute(): ?array
    {
        return $this->sofascore_data['injuries']['away'] ?? null;
    }

    public function getH2hAttribute(): ?array
    {
        return $this->sofascore_data['h2h'] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS - FootyStats
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function getHomeStreaksAttribute(): ?array
    {
        return $this->footystats_data['series']['home'] ?? null;
    }

    public function getAwayStreaksAttribute(): ?array
    {
        return $this->footystats_data['series']['away'] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS - FBRef
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function getHomeStandingAttribute(): ?array
    {
        return $this->fbref_data['league']['home'] ?? null;
    }

    public function getAwayStandingAttribute(): ?array
    {
        return $this->fbref_data['league']['away'] ?? null;
    }

    public function getHomeTopScorerAttribute(): ?array
    {
        return $this->fbref_data['topPlayers']['home']['topScorer'] ?? null;
    }

    public function getAwayTopScorerAttribute(): ?array
    {
        return $this->fbref_data['topPlayers']['away']['topScorer'] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS - Contexte
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function getImportanceAttribute(): ?string
    {
        return $this->context_data['importance'] ?? null;
    }

    public function getContextReasonAttribute(): ?string
    {
        return $this->context_data['reason'] ?? null;
    }

    public function getHomeRestDaysAttribute(): ?int
    {
        return $this->context_data['restDays']['home'] ?? null;
    }

    public function getAwayRestDaysAttribute(): ?int
    {
        return $this->context_data['restDays']['away'] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS - Dimensions Layer 2
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function getDimensionScoreAttribute(): array
    {
        $dimensions = $this->dimensions ?? [];
        $scores = [];
        
        foreach (['form', 'injuries', 'h2h', 'tactical', 'xg', 'context'] as $dim) {
            $scores[$dim] = $dimensions[$dim]['score'] ?? null;
        }
        
        return $scores;
    }

    public function getRiskFactorsAttribute(): array
    {
        return $this->tactical_insights['riskFactors'] ?? [];
    }

    public function getHomeAdvantagesAttribute(): array
    {
        return $this->tactical_insights['homeAdvantages'] ?? [];
    }

    public function getAwayAdvantagesAttribute(): array
    {
        return $this->tactical_insights['awayAdvantages'] ?? [];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MÉTHODES UTILITAIRES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Vérifie si des données tactiques sont présentes
     */
    public function hasTacticalData(): bool
    {
        return !empty($this->tactical_data);
    }

    /**
     * Vérifie si des données Sofascore sont présentes
     */
    public function hasSofascoreData(): bool
    {
        return !empty($this->sofascore_data);
    }

    /**
     * Vérifie si des données FootyStats sont présentes
     */
    public function hasFootystatsData(): bool
    {
        return !empty($this->footystats_data);
    }

    /**
     * Vérifie si des données FBRef sont présentes
     */
    public function hasFbrefData(): bool
    {
        return !empty($this->fbref_data);
    }

    /**
     * Vérifie si des données de contexte sont présentes
     */
    public function hasContextData(): bool
    {
        return !empty($this->context_data);
    }

    /**
     * Compte le nombre de sources de données Layer 2
     */
    public function getDataSourcesCountAttribute(): int
    {
        $count = 0;
        if ($this->hasTacticalData()) $count++;
        if ($this->hasSofascoreData()) $count++;
        if ($this->hasFootystatsData()) $count++;
        if ($this->hasFbrefData()) $count++;
        if ($this->hasContextData()) $count++;
        return $count;
    }
}
