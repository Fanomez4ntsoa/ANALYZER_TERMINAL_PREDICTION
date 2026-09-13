<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
