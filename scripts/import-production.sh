#!/usr/bin/env bash
#
# Import, sur le VPS, des données exportées par scripts/export-production.sh
# (docs/deploiement.md, étape 7).
#
# - vérifie l'intégrité des fichiers copiés (SHA256SUMS) ;
# - refuse d'importer si l'une des six tables n'est pas vide : jamais de mélange
#   avec des données du VPS, jamais d'écrasement ;
# - importe dans l'ordre des clés étrangères, identifiants conservés ;
# - compare la base au manifeste du portable et échoue au premier écart.
#
# Le schéma doit exister (setup.sh, migrate) et historical_matches être importé
# (football-data:import) : le manifeste en compte les lignes.
#
# Usage : scripts/import-production.sh <dossier d'export copié>

set -euo pipefail

cd "$(dirname "$0")/.."

TABLES=(matches odds_movements advanced_data predictions prediction_log pipeline_runs)

fail() { echo "ÉCHEC : $*" >&2; exit 1; }

DIR="${1:-}"
[ -n "$DIR" ] && [ -d "$DIR" ] || fail "usage : scripts/import-production.sh <dossier d'export copié>"
DIR="$(cd "$DIR" && pwd)"
for f in production.sql.gz manifest.json SHA256SUMS; do
    [ -f "$DIR/$f" ] || fail "fichier manquant : $DIR/$f"
done

# 1. Intégrité
(cd "$DIR" && sha256sum --check --quiet SHA256SUMS) || fail "fichiers corrompus pendant la copie : recopier le dossier"
echo "Intégrité : OK"

CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
DB="$(php artisan db:client-options "$CNF")"
[ -n "$DB" ] || fail "nom de base introuvable (db:client-options)"

# 2. Tables vides, sinon refus
for t in "${TABLES[@]}"; do
    n="$(mysql --defaults-extra-file="$CNF" -N -B -e "SELECT COUNT(*) FROM \`$t\`" "$DB")" || fail "table $t illisible : migrate a-t-il tourné ?"
    [ "$n" = "0" ] || fail "la table $t contient déjà $n ligne(s). Import refusé : rien n'a été écrit. Voir docs/deploiement.md, « Si l'import échoue »."
done
echo "Tables cibles vides : OK"

# 3. Import. Le fichier désactive FOREIGN_KEY_CHECKS en tête et respecte l'ordre
#    des clés étrangères ; --force est proscrit : la première erreur arrête tout.
echo "Import dans ${DB}…"
#    Session en UTC : le dump (--skip-tz-utc) porte les heures telles que Laravel
#    les écrit, en UTC, sans instruction de fuseau.
gunzip -c "$DIR/production.sql.gz" | mysql --defaults-extra-file="$CNF" --default-character-set=utf8mb4 --init-command="SET time_zone='+00:00'" "$DB" \
    || fail "erreur pendant l'import : voir docs/deploiement.md, « Si l'import échoue »"

# 4. Comparaison au manifeste du portable
php artisan data:manifest --compare="$DIR/manifest.json" || fail "la base importée diffère du manifeste du portable"

echo
echo "Import conforme au portable."
