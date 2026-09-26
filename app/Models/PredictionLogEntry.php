<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne du journal des sélections : une prédiction figée au moment du calcul.
 *
 * Jamais modifiée ni supprimée, sauf ses colonnes de clôture, écrites une seule
 * fois par log:settle. La garde passe par les événements Eloquent : aucune mise à
 * jour de masse (query builder) ne doit toucher cette table.
 *
 * closing_edge n'est pas le CLV de /market : c'est la cote Bet365 du calcul
 * contre la probabilité équitable Pinnacle à la clôture. Bet365 étant moins
 * généreux que Pinnacle marge retirée, son signe absolu ne dit rien des
 * sélections ; seule sa variation entre segments informe (docs/decisions.md).
 */
class PredictionLogEntry extends Model
{
    public const TRIGGER_PIPELINE = 'pipeline';
    public const TRIGGER_MANUAL = 'manual';

    public const SETTLEMENT_COLUMNS = [
        'score_home',
        'score_away',
        'outcome_occurred',
        'closing_bookmaker',
        'closing_odds',
        'closing_fair_probability',
        'closing_quoted_at',
        'closing_odds_movement_id',
        'closing_edge',
        'void_reason',
        'settled_at',
    ];

    protected $table = 'prediction_log';

    public $timestamps = false;

    protected $fillable = [
        'match_id',
        'league_id',
        'home_team',
        'away_team',
        'kickoff_at',
        'trigger',
        'market',
        'outcome',
        'model_probability',
        'full_model_probability',
        'full_model_signals',
        'model_mode',
        'lambda_home',
        'lambda_away',
        'rho',
        'odds',
        'bookmaker',
        'fair_probability',
        'odds_taken_at',
        'computed_at',
        ...self::SETTLEMENT_COLUMNS,
    ];

    protected $casts = [
        'kickoff_at' => 'datetime',
        'model_probability' => 'float',
        'full_model_probability' => 'float',
        'full_model_signals' => 'array',
        'lambda_home' => 'float',
        'lambda_away' => 'float',
        'rho' => 'float',
        'odds' => 'float',
        'fair_probability' => 'float',
        'odds_taken_at' => 'datetime',
        'computed_at' => 'datetime',
        'score_home' => 'integer',
        'score_away' => 'integer',
        'outcome_occurred' => 'boolean',
        'closing_odds' => 'float',
        'closing_fair_probability' => 'float',
        'closing_quoted_at' => 'datetime',
        'closing_edge' => 'float',
        'settled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (!in_array($entry->trigger, [self::TRIGGER_PIPELINE, self::TRIGGER_MANUAL], true)) {
                throw new \LogicException("Journal : déclencheur inconnu « {$entry->trigger} »");
            }
            if (!self::admits(['odds' => $entry->odds, 'fair_probability' => $entry->fair_probability, 'bookmaker' => $entry->bookmaker])) {
                throw new \LogicException("Journal : ligne sans cote jouable identifiée (match #{$entry->match_id}, {$entry->market} {$entry->outcome})");
            }
            if ($entry->computed_at === null || $entry->kickoff_at === null || $entry->computed_at->gte($entry->kickoff_at)) {
                throw new \LogicException("Journal : aucune ligne écrite au coup d'envoi ou après (match #{$entry->match_id})");
            }
            foreach (self::SETTLEMENT_COLUMNS as $column) {
                if ($entry->getAttribute($column) !== null) {
                    throw new \LogicException("Journal : une ligne naît en attente, {$column} doit être nul");
                }
            }
        });

        static::updating(function (self $entry) {
            // Non clôturable et clôturée s'excluent, dans les deux sens
            if ($entry->getRawOriginal('void_reason') !== null) {
                throw new \LogicException("Journal : ligne #{$entry->id} déclarée non clôturable, plus rien ne s'y écrit");
            }
            if ($entry->getRawOriginal('outcome_occurred') !== null && $entry->isDirty('void_reason')) {
                throw new \LogicException("Journal : ligne #{$entry->id} déjà clôturée, elle ne devient pas non clôturable");
            }
            foreach (array_keys($entry->getDirty()) as $column) {
                if (!in_array($column, self::SETTLEMENT_COLUMNS, true)) {
                    throw new \LogicException("Journal : ligne #{$entry->id} figée, {$column} ne se modifie pas");
                }
                if ($entry->getRawOriginal($column) !== null) {
                    throw new \LogicException("Journal : ligne #{$entry->id} déjà clôturée, {$column} ne se réécrit pas");
                }
            }
        });

        static::deleting(function (self $entry) {
            throw new \LogicException("Journal : ligne #{$entry->id} non supprimable");
        });
    }

    /**
     * Une ligne calculée entre au journal si elle porte une cote d'un bookmaker
     * identifié (jamais legacy_max, maximum multi-bookmakers) et une probabilité
     * équitable.
     */
    public static function admits(array $row): bool
    {
        return ($row['odds'] ?? null) !== null
            && ($row['fair_probability'] ?? null) !== null
            && !empty($row['bookmaker'])
            && $row['bookmaker'] !== 'legacy_max';
    }

    public function scopeSettled($query)
    {
        return $query->whereNotNull('outcome_occurred');
    }

    /** Ni clôturée ni déclarée non clôturable. */
    public function scopePending($query)
    {
        return $query->whereNull('outcome_occurred')->whereNull('void_reason');
    }

    /** Définitivement non clôturable : jamais mesurée, jamais en attente. */
    public function scopeVoided($query)
    {
        return $query->whereNotNull('void_reason');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function closingSnapshot(): BelongsTo
    {
        return $this->belongsTo(OddsMovement::class, 'closing_odds_movement_id');
    }
}
