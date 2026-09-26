<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Compte de l'interface, créé en ligne de commande (setup.sh). Le mot de passe est
 * saisi sans écho et jamais passé en argument : il apparaîtrait dans la liste des
 * processus et l'historique du shell.
 *
 * Relançable : un compte existant est laissé tel quel, sauf si l'on choisit d'en
 * changer le mot de passe.
 */
class UserCreate extends Command
{
    protected $signature = 'user:create';

    protected $description = 'Créer le compte de l\'interface, ou changer son mot de passe (saisie sans écho)';

    private const MIN_PASSWORD_LENGTH = 12;

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->ask('E-mail du compte')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error("E-mail invalide : « {$email} »");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user !== null && !$this->confirm("Le compte {$email} existe déjà. Changer son mot de passe ?", false)) {
            $this->info("Compte {$email} conservé tel quel.");

            return self::SUCCESS;
        }

        $password = $this->askPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        if ($user !== null) {
            $user->update(['password' => Hash::make($password)]);
            $this->info("Mot de passe du compte {$email} changé.");

            return self::SUCCESS;
        }

        $name = trim((string) $this->ask('Nom affiché', strstr($email, '@', true) ?: $email));
        User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        $this->info("Compte {$email} créé.");

        return self::SUCCESS;
    }

    /** Deux saisies sans écho, identiques, d'au moins MIN_PASSWORD_LENGTH caractères. */
    private function askPassword(): ?string
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $password = (string) $this->secret('Mot de passe (' . self::MIN_PASSWORD_LENGTH . ' caractères au moins, rien ne s\'affiche)');
            if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $this->warn('Trop court.');
                continue;
            }
            if ($password !== (string) $this->secret('Le même, une seconde fois')) {
                $this->warn('Les deux saisies diffèrent.');
                continue;
            }

            return $password;
        }

        $this->error('Trois essais sans mot de passe valide : compte non modifié.');

        return null;
    }
}
