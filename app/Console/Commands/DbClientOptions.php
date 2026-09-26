<?php

namespace App\Console\Commands;

use App\Services\DataPipeline\DatabaseBackup;
use Illuminate\Console\Command;

/**
 * Écrit un fichier d'options client MariaDB (droits 600) avec les identifiants de
 * .env, et affiche le nom de la base. Pour scripts/export-production.sh et
 * import-production.sh : le mot de passe ne passe jamais sur la ligne de commande
 * ni dans l'environnement, et .env n'est lu que par Laravel.
 */
class DbClientOptions extends Command
{
    protected $signature = 'db:client-options {path : Fichier à écrire (le script le supprime ensuite)}';

    protected $description = 'Écrire un fichier d\'options client MariaDB (identifiants de .env) et afficher le nom de la base';

    public function handle(DatabaseBackup $backup): int
    {
        $this->line($backup->writeClientOptions($this->argument('path')));

        return self::SUCCESS;
    }
}
