#!/usr/bin/env bash
#
# setup.sh — installation du collecteur football-analyzer sur le VPS.
# Procédure complète : docs/deploiement.md.
#
# CE SCRIPT NE TOUCHE À RIEN DE GLOBAL. Le VPS héberge déjà une application en
# production : aucune installation ni mise à jour de paquet, aucune modification
# de la configuration de MariaDB, de PHP ou du fuseau système, aucun sudo. Il
# vérifie, et s'arrête en nommant ce qui manque ; l'installation reste à faire à
# la main. Il n'écrit que dans ce dossier et dans la base dédiée du projet.
#
# Idempotent : relançable à tout moment. Chaque étape constate ce qui est déjà
# fait et ne le refait pas (composer et migrate sont eux-mêmes idempotents).
#
# Ce qu'il NE fait PAS, volontairement :
#   - npm install / npm run build : aucun serveur web sur le VPS, l'interface ne
#     s'y consulte pas. Les vues (@vite) n'y fonctionneraient pas, et c'est voulu :
#     on lit l'interface en local, sur une copie importée.
#   - config:cache (ni optimize, qui l'inclut) : avec une configuration en cache,
#     Laravel ignore phpunit.xml, et le 15/09/2026 une suite de tests lancée ainsi a
#     vidé la vraie base (RefreshDatabase sur MariaDB). Le gain est négligeable pour
#     des commandes lancées une fois par jour ou toutes les cinq minutes.
#   - les tests : jamais sur le VPS. Ils tournent sur le portable, sur SQLite en
#     mémoire (composer install --no-dev n'installe d'ailleurs pas PHPUnit).
#   - la crontab : installée à la main, APRÈS l'import des données (étape 8 du
#     guide). Un passage lancé avant l'import remplirait les tables et bloquerait
#     l'import.

set -euo pipefail

cd "$(dirname "$0")"
PROJECT_DIR="$(pwd)"

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
ok()   { printf '   ok  %s\n' "$*"; }
fail() { printf '\n\033[1;31mARRÊT : %s\033[0m\n' "$1" >&2; shift; for l in "$@"; do printf '       %s\n' "$l" >&2; done; exit 1; }

# Valeur d'une clé de .env (dernière occurrence, guillemets retirés). Sert aux
# contrôles de présence seulement : les secrets ne sont jamais affichés.
env_get() {
    local line
    line="$(grep -E "^$1=" .env 2>/dev/null | tail -n 1 || true)"
    line="${line#*=}"
    line="${line%\"}"; line="${line#\"}"
    line="${line%\'}"; line="${line#\'}"
    printf '%s' "$line"
}

# ─────────────────────────────────────────────────────────────────────────────
step "1. Utilisateur"
[ "$(id -u)" -ne 0 ] || fail "ne pas lancer en root." \
    "Lancer en tant que l'utilisateur propriétaire du projet (deploy), celui qui portera la crontab."
ok "$(id -un), projet dans ${PROJECT_DIR}"

# ─────────────────────────────────────────────────────────────────────────────
step "2. Prérequis (vérifiés, jamais installés)"

missing=()
for bin in php composer mysql mysqldump gzip gunzip sha256sum; do
    command -v "$bin" >/dev/null 2>&1 || missing+=("$bin")
done
[ ${#missing[@]} -eq 0 ] || fail "commande(s) absente(s) : ${missing[*]}" \
    "Les installer vous-même (ce script n'installe rien), puis relancer ./setup.sh."
ok "php, composer, mysql, mysqldump, gzip, sha256sum"

php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
    || fail "PHP $(php -r 'echo PHP_VERSION;') trop ancien : 8.2 au moins (composer.json)."
ok "PHP $(php -r 'echo PHP_VERSION;')"

# Extensions exigées par Laravel, plus pdo_mysql (MariaDB), zip (archives
# football-data), intl et bcmath
required_ext=(ctype curl dom fileinfo filter hash mbstring openssl pcre pdo pdo_mysql session tokenizer xml zip intl bcmath)
loaded="$(php -m | tr '[:upper:]' '[:lower:]')"
missing=()
for ext in "${required_ext[@]}"; do
    grep -qx "$ext" <<<"$loaded" || missing+=("$ext")
done
if [ ${#missing[@]} -gt 0 ]; then
    php_minor="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    fail "extension(s) PHP absente(s) du CLI : ${missing[*]}" \
        "Sous Ubuntu, paquets probables : $(printf "php${php_minor}-%s " "${missing[@]}")" \
        "Les installer vous-même, puis relancer ./setup.sh."
fi
ok "extensions : ${required_ext[*]}"

# Fuseau : lu, jamais modifié
tz="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
[ -n "$tz" ] || tz="$(date +%Z)"
case "$tz" in
    UTC|Etc/UTC|Etc/Universal|Universal|Zulu) ok "fuseau système ${tz}" ;;
    *) fail "fuseau système « ${tz} », UTC attendu." \
        "Ce script ne le change pas (autre application en production sur ce serveur)." \
        "Décider vous-même (timedatectl set-timezone Etc/UTC), puis relancer." ;;
esac

# ─────────────────────────────────────────────────────────────────────────────
step "3. Dépendances PHP (sans outils de développement)"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
ok "vendor/ à jour"

# ─────────────────────────────────────────────────────────────────────────────
step "4. Fichier .env"
[ -f .env ] || fail ".env absent." \
    "cp deploy/env.vps.example .env   puis remplir les valeurs <...> (docs/deploiement.md, étape 3)."
chmod 600 .env
ok ".env présent, droits 600"

problems=()
for key in API_FOOTBALL_KEY ODDS_API_KEY OPENWEATHERMAP_KEY DB_DATABASE DB_USERNAME DB_PASSWORD; do
    value="$(env_get "$key")"
    { [ -z "$value" ] || [[ "$value" == \<* ]]; } && problems+=("$key vide ou non rempli")
done
case "$(env_get DB_CONNECTION)" in mariadb|mysql) ;; *) problems+=("DB_CONNECTION doit valoir mariadb") ;; esac
[ "$(env_get APP_ENV)" = "production" ] || problems+=("APP_ENV doit valoir production")
[ "$(env_get APP_DEBUG)" = "false" ] || problems+=("APP_DEBUG doit valoir false")
[ "$(env_get PIPELINE_ENABLED)" = "true" ] || problems+=("PIPELINE_ENABLED doit valoir true : le VPS est la seule machine qui collecte")
[ "$(env_get DB_TIMEZONE)" = "+00:00" ] || problems+=("DB_TIMEZONE doit valoir +00:00 : heures des TIMESTAMP en UTC (docs/deploiement.md, « Heures et fuseaux »)")
[ ${#problems[@]} -eq 0 ] || fail ".env incomplet :" "${problems[@]}"
ok "clés API-Football, The Odds API, OpenWeatherMap renseignées (trois : ANTHROPIC_API_KEY n'est plus lue)"
ok "APP_ENV=production, APP_DEBUG=false, PIPELINE_ENABLED=true, DB_TIMEZONE=+00:00"

# Jamais de configuration en cache (voir l'en-tête). Si un cache traîne, on le retire.
if [ -f bootstrap/cache/config.php ]; then
    php artisan config:clear
    ok "cache de configuration trouvé et retiré (config:clear)"
else
    ok "aucun cache de configuration"
fi

if [ -z "$(env_get APP_KEY)" ]; then
    php artisan key:generate --force
    ok "APP_KEY générée"
else
    ok "APP_KEY déjà présente, conservée"
fi

# ─────────────────────────────────────────────────────────────────────────────
step "5. Base de données dédiée"
db="$(env_get DB_DATABASE)"; dbuser="$(env_get DB_USERNAME)"
if ! php artisan tinker --execute='DB::connection()->getPdo(); echo "connexion-ok";' 2>/dev/null | grep -q connexion-ok; then
    fail "connexion impossible à la base « ${db} » avec l'utilisateur « ${dbuser} »." \
        "Créer la base et son utilisateur dédié (root s'authentifie par socket) :" \
        "" \
        "  sudo mysql" \
        "  CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
        "  CREATE USER '${dbuser}'@'localhost' IDENTIFIED BY '<le DB_PASSWORD du .env>';" \
        "  CREATE USER '${dbuser}'@'127.0.0.1' IDENTIFIED BY '<le DB_PASSWORD du .env>';" \
        "  GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${dbuser}'@'localhost', '${dbuser}'@'127.0.0.1';" \
        "  FLUSH PRIVILEGES;" \
        "" \
        "Droits limités à cette base : les autres bases du serveur restent inaccessibles." \
        "Puis relancer ./setup.sh."
fi
ok "connexion à « ${db} »"

# Fuseau de la session MariaDB de Laravel, mesuré AVANT toute écriture en base
# (migrate vient après) : décalage effectif entre NOW() et UTC_TIMESTAMP(), quel
# que soit le nom du fuseau. DB_TIMEZONE=+00:00 le fixe pour chaque connexion de
# Laravel, sans toucher au réglage global du serveur (lu, affiché, jamais modifié).
# C'est la faute du portable (session en EAT, TIMESTAMP stockés décalés de 3 h) :
# elle ne peut pas se reproduire ici sans que ce contrôle arrête le script.
tz_probe="$(php artisan tinker --execute='$r = DB::selectOne("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) o, @@session.time_zone s, @@global.time_zone g, @@system_time_zone y"); echo "tz=".$r->o."|".$r->s."|".$r->g."|".$r->y;' 2>/dev/null | grep -o 'tz=.*' | cut -d= -f2 || true)"
IFS='|' read -r tz_offset tz_session tz_global tz_system <<<"$tz_probe"
[ "$tz_offset" = "0" ] || fail "session MariaDB décalée de « ${tz_offset:-?} » minute(s) sur UTC (session ${tz_session:-?}, global ${tz_global:-?}, système ${tz_system:-?})." \
    "Les heures du journal (TIMESTAMP) seraient stockées décalées. Vérifier DB_TIMEZONE=+00:00 dans .env." \
    "Voir docs/deploiement.md, « Heures et fuseaux »."
ok "session MariaDB en UTC, décalage 0 (session ${tz_session}, global ${tz_global}, système ${tz_system} : réglage global non touché)"

php artisan migrate --force
ok "schéma à jour"

# ─────────────────────────────────────────────────────────────────────────────
step "6. Dossiers de stockage"
dirs=(storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views
      storage/app/private/backups storage/app/private/football-data storage/app/private/backtest
      storage/app/private/export bootstrap/cache)
mkdir -p "${dirs[@]}"
foreign="$(find storage bootstrap/cache ! -user "$(id -un)" -print -quit)"
[ -z "$foreign" ] || fail "fichier n'appartenant pas à $(id -un) : ${foreign}" \
    "Ce script ne lance pas sudo. Corriger vous-même : sudo chown -R $(id -un): ${PROJECT_DIR}/storage ${PROJECT_DIR}/bootstrap/cache"
chmod -R u+rwX,g+rwX storage bootstrap/cache
chmod 700 storage/app/private/backups storage/app/private/export
ok "${#dirs[@]} dossiers, propriétaire $(id -un), sauvegardes et exports en 700"

# ─────────────────────────────────────────────────────────────────────────────
step "7. Compte de l'interface"
users="$(php artisan tinker --execute='echo "n=".App\Models\User::count();' 2>/dev/null | grep -o 'n=[0-9]*' | cut -d= -f2 || true)"
if [ ! -t 0 ]; then
    ok "pas de terminal interactif : étape sautée (php artisan user:create plus tard)"
elif [ "${users:-0}" = "0" ]; then
    php artisan user:create
else
    read -r -p "   ${users} compte(s) existe(nt) déjà. Créer un compte ou changer un mot de passe ? [o/N] " answer
    if [[ "$answer" =~ ^[oOyY]$ ]]; then php artisan user:create; else ok "comptes conservés"; fi
fi

# ─────────────────────────────────────────────────────────────────────────────
step "8. État"
php artisan system:status || true

cat <<EOF

setup.sh terminé. Les voyants CRITIQUE ou ALERTE ci-dessus sont attendus tant que
les étapes suivantes ne sont pas faites (docs/deploiement.md) :

  4. historique football-data : zips copiés, puis  php artisan football-data:import
  5. backtest de référence    :                    php artisan backtest:reference
  6-7. bascule                : export sur le portable, puis  scripts/import-production.sh <dossier>
  8. crontab, en dernier      :  crontab -e  puis ajouter :
     * * * * * cd ${PROJECT_DIR} && php artisan schedule:run >> /dev/null 2>&1
EOF
