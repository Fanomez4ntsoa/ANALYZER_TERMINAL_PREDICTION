<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use App\Services\Backtesting\FootballData\CalibrationBacktestService;
use App\Services\Backtesting\SeasonScopedEstimator;
use App\Services\Probability\DixonColesRho;
use App\Services\Probability\LeagueGoalAverages;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Moyennes de buts par ligue (saisons de travail) : une lecture par processus.
        $this->app->singleton(LeagueGoalAverages::class, fn () => new LeagueGoalAverages());
        $this->app->singleton(DixonColesRho::class, fn () => new DixonColesRho());

        // Règle du backtest : tout estimateur de paramètre sur données historiques est
        // tagué ici, et le moteur le borne aux saisons strictement antérieures au match.
        $this->app->tag([LeagueGoalAverages::class, DixonColesRho::class], SeasonScopedEstimator::TAG);
        $this->app->when(CalibrationBacktestService::class)
            ->needs('$estimators')
            ->giveTagged(SeasonScopedEstimator::TAG);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mode strict partiel : un attribut absent de $fillable passé à
        // create()/update()/fill() lève une exception au lieu d'être perdu.
        // preventLazyLoading() n'est volontairement pas activé : XGModelService
        // et les vues chargent des relations à la demande.
        Model::preventSilentlyDiscardingAttributes();
    }
}
