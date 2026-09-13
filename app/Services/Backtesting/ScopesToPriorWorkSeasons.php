<?php

namespace App\Services\Backtesting;

/**
 * Implémentation commune de SeasonScopedEstimator : saisons d'estimation =
 * saisons de travail de config('football-data'), strictement antérieures à la
 * saison bornée s'il y en a une. Jamais les saisons réservées.
 */
trait ScopesToPriorWorkSeasons
{
    private ?string $scopeSeason = null;
    private bool $scopeRequired = false;

    public function scopeToSeasonsBefore(?string $season): void
    {
        $this->scopeSeason = $season;
    }

    public function requireScope(bool $required): void
    {
        $this->scopeRequired = $required;
    }

    public function isScoped(): bool
    {
        return $this->scopeSeason !== null;
    }

    /** @return string[] */
    public function allowedSeasons(): array
    {
        $work = config('football-data.work_seasons', []);
        if ($this->scopeSeason === null) {
            return $work;
        }

        // Saisons AABB : l'ordre lexicographique est l'ordre chronologique.
        return array_values(array_filter($work, fn (string $s) => strcmp($s, $this->scopeSeason) < 0));
    }

    private function assertScopeIfRequired(): void
    {
        if ($this->scopeRequired && $this->scopeSeason === null) {
            throw new \LogicException(static::class . ' lu sans bornage de saison pendant un backtest : fuite temporelle refusée.');
        }
    }

    /**
     * Garde-fou sur les données d'estimation effectivement lues : toute ligne d'une
     * saison non autorisée (réservée, ou non strictement antérieure au match) lève
     * une exception, quelle que soit la façon dont elle a été chargée.
     *
     * @param iterable<string> $seasons
     */
    private function assertTrainingSeasons(iterable $seasons): void
    {
        $allowed = array_flip($this->allowedSeasons());
        foreach ($seasons as $season) {
            $season = (string) $season;
            if (!isset($allowed[$season]) || ($this->scopeSeason !== null && strcmp($season, $this->scopeSeason) >= 0)) {
                throw new \LogicException(static::class . " : saison {$season} utilisée pour estimer un paramètre alors que la saison évaluée est " . ($this->scopeSeason ?? 'non bornée') . ' (saisons autorisées : ' . (implode(',', array_keys($allowed)) ?: 'aucune') . ').');
            }
        }
    }
}
