<?php

namespace App\Services\Backtesting;

/**
 * Règle générale du moteur de backtest : tout paramètre estimé sur des matchs
 * historiques ne peut l'être que sur des saisons STRICTEMENT antérieures à celle
 * du match évalué.
 *
 * Tout service qui estime un paramètre depuis historical_matches implémente cette
 * interface et est enregistré sous le tag ci-dessous (AppServiceProvider). Le moteur
 * de backtest borne chaque estimateur à la saison du match avant de le prédire, et
 * exige ce bornage pendant toute la durée du run : un accès non borné lève une
 * exception au lieu de fuir silencieusement.
 */
interface SeasonScopedEstimator
{
    public const TAG = 'backtest.season_scoped_estimators';

    /** null = pas de bornage (production : données disponibles aujourd'hui). */
    public function scopeToSeasonsBefore(?string $season): void;

    /** true = tout accès sans bornage lève une exception (pendant un backtest). */
    public function requireScope(bool $required): void;
}
