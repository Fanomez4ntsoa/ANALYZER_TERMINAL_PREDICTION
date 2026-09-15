<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Garde contre la destruction de la vraie base.
     *
     * Le 15/09/2026, la suite a tourné avec une configuration en cache : Laravel a
     * ignoré phpunit.xml (SQLite en mémoire), et RefreshDatabase a lancé
     * migrate:fresh sur la base MariaDB réelle, vidée entièrement
     * (docs/decisions.md). config:cache et les tests ne cohabitent jamais.
     *
     * Vérifié avant que le moindre trait (RefreshDatabase) ne touche une base :
     * createApplication précède setUpTraits.
     */
    public function createApplication(): Application
    {
        // Avant même de démarrer l'application : le fichier de cache par défaut
        $defaultCache = dirname(__DIR__) . '/bootstrap/cache/config.php';
        if (is_file($defaultCache)) {
            self::refuse("configuration en cache ({$defaultCache})");
        }

        $app = parent::createApplication();

        // Chemin de cache déplacé (APP_CONFIG_CACHE) ou tout autre cache chargé
        if ($app->configurationIsCached()) {
            self::refuse('configuration en cache (' . $app->getCachedConfigPath() . ')');
        }

        $default = $app['config']->get('database.default');
        $driver = $app['config']->get("database.connections.{$default}.driver");
        if ($driver !== 'sqlite') {
            self::refuse("connexion de test « {$default} » (pilote {$driver}), SQLite exigé");
        }

        return $app;
    }

    private static function refuse(string $reason): never
    {
        fwrite(STDERR, "\n\nTESTS REFUSÉS : {$reason}.\n"
            . "RefreshDatabase viderait la base réelle. Lancer `php artisan config:clear`,\n"
            . "vérifier que DB_CONNECTION n'est pas forcé dans l'environnement, puis relancer.\n"
            . "config:cache et les tests ne cohabitent jamais (docs/decisions.md, 15/09/2026).\n\n");

        exit(1);
    }
}
