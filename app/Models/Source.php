<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'source_type',
        'predictions',
    ];

    protected $casts = [
        'predictions' => 'array',
    ];

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // RELATIONS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Une source appartient à un match
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id', 'id');
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ACCESSEURS (Accès facile aux prédictions)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Retourne les prédictions décodées (le cast 'array' gère le JSON)
     */
    private function getDecodedPredictions(): array
    {
        return is_array($this->predictions) ? $this->predictions : [];
    }

    /**
     * Prédiction 1X2
     */
    public function getWinnerAttribute(): ?array
    {
        return $this->getDecodedPredictions()['winner'] ?? null;
    }

    /**
     * Prédiction Over/Under
     */
    public function getOverUnderAttribute(): ?array
    {
        return $this->getDecodedPredictions()['overUnder'] ?? null;
    }

    /**
     * Prédiction BTTS
     */
    public function getBttsAttribute(): ?array
    {
        return $this->getDecodedPredictions()['btts'] ?? null;
    }

    /**
     * Prédiction Double Chance
     */
    public function getDoubleChanceAttribute(): ?array
    {
        return $this->getDecodedPredictions()['doubleChance'] ?? null;
    }

    /**
     * Prédiction Score Exact
     */
    public function getExactScoreAttribute(): ?array
    {
        return $this->getDecodedPredictions()['exactScore'] ?? null;
    }

    /**
     * Prédiction HT/FT
     */
    public function getHtftAttribute(): ?array
    {
        return $this->getDecodedPredictions()['htft'] ?? null;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // MÉTHODES UTILITAIRES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Récupère une prédiction par marché
     */
    public function getPrediction(string $market): ?array
    {
        return $this->getDecodedPredictions()[$market] ?? null;
    }

    /**
     * Récupère le pick pour un marché donné
     */
    public function getPick(string $market): ?string
    {
        $prediction = $this->getPrediction($market);
        
        if (!$prediction) {
            return null;
        }

        // Source C a une structure différente pour winner
        if ($market === 'winner' && $this->source_type === 'C') {
            // Retourne le résultat avec la plus haute probabilité
            $home = $prediction['home'] ?? 0;
            $draw = $prediction['draw'] ?? 0;
            $away = $prediction['away'] ?? 0;
            
            if ($home >= $draw && $home >= $away) return '1';
            if ($draw >= $home && $draw >= $away) return 'X';
            return '2';
        }

        return $prediction['pick'] ?? null;
    }

    /**
     * Récupère la confiance pour un marché donné
     */
    public function getConfidence(string $market): ?int
    {
        $prediction = $this->getPrediction($market);
        
        if (!$prediction) {
            return null;
        }

        // Source C winner: prendre la valeur max
        if ($market === 'winner' && $this->source_type === 'C') {
            return (int) max(
                $prediction['home'] ?? 0,
                $prediction['draw'] ?? 0,
                $prediction['away'] ?? 0
            );
        }

        return $prediction['confidence'] ?? null;
    }
}
