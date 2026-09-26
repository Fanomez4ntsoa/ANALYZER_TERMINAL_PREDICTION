<?php

namespace App\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Une seule machine collecte : celle dont la base fait foi (pipeline.enabled).
 *
 * Toute commande qui appelle API-Football, The Odds API ou OpenWeatherMap pour
 * alimenter la base passe par ici. Deux machines qui collectent partageraient les
 * quotas (100 requêtes API-Football par jour, 500 crédits The Odds API par mois) et
 * leurs bases divergeraient sans réconciliation possible. Les lectures (log:report,
 * system:status, interface) et la clôture du journal restent libres.
 */
final class CollectionGuard
{
    /**
     * Refuse la commande si la collecte est désactivée sur cette machine, en
     * expliquant pourquoi et quelle machine fait foi.
     *
     * @return bool true si la commande doit s'arrêter
     */
    public static function refuses(Command $command): bool
    {
        if (config('pipeline.enabled') === true) {
            return false;
        }

        $name = $command->getName();
        $reference = config('pipeline.reference_host');

        $command->error("{$name} refusé : la collecte est désactivée sur cette machine (PIPELINE_ENABLED n'est pas à true dans .env).");
        $command->line("La base qui fait foi est celle de {$reference}. C'est la seule machine qui collecte.");
        $command->line('Pourquoi : une seconde machine qui collecte partagerait avec elle le quota API-Football (100 requêtes par jour)');
        $command->line('et les crédits The Odds API (500 par mois), et les deux bases divergeraient : matchs, cotes et journal');
        $command->line('différents, impossibles à réconcilier.');
        $command->line('Pour lire des données à jour ici : importer une sauvegarde du VPS (docs/deploiement.md, « Copie vers le portable »).');
        $command->line('PIPELINE_ENABLED=true ne se met que sur la machine de référence.');

        Log::channel('pipeline')->warning("{$name} refusé : collecte désactivée sur cette machine (PIPELINE_ENABLED), base de référence : {$reference}");

        return true;
    }
}
