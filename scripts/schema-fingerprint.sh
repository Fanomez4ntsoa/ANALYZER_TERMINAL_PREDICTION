#!/usr/bin/env bash
#
# Empreinte du schéma de la base du projet : migrations passées, puis SHOW CREATE
# TABLE de chaque table, compteurs AUTO_INCREMENT retirés. Lecture seule.
#
# Deux serveurs MariaDB peuvent donner des structures différentes pour les mêmes
# migrations (explicit_defaults_for_timestamp : 1 sur le portable, 0 sur le VPS ;
# le 27/09/2026, des ON UPDATE current_timestamp() silencieux). La référence,
# deploy/schema-reference.txt, est produite sur le portable ; setup.sh compare le
# VPS à elle après chaque migrate et s'arrête au premier écart.
#
# Usage :
#   scripts/schema-fingerprint.sh                    empreinte sur la sortie
#   scripts/schema-fingerprint.sh --check <fichier>  compare, affiche l'écart, code 1
#
# Régénérer la référence (portable, après php artisan migrate) :
#   scripts/schema-fingerprint.sh > deploy/schema-reference.txt

set -euo pipefail

cd "$(dirname "$0")/.."

fail() { echo "ÉCHEC : $*" >&2; exit 1; }

CNF="$(mktemp)"
OUT="$(mktemp)"
trap 'rm -f "$CNF" "$OUT"' EXIT
DB="$(php artisan db:client-options "$CNF")"
[ -n "$DB" ] || fail "nom de base introuvable (db:client-options)"

q() { mysql --defaults-extra-file="$CNF" -N -B -r -e "$1" "$DB"; }

{
    echo "# Migrations passées"
    q "SELECT migration FROM migrations" | LC_ALL=C sort
    for t in $(q "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'" | LC_ALL=C sort); do
        echo
        echo "# Table $t"
        # « nom<TAB>CREATE TABLE … » : seule la première ligne porte le nom
        q "SHOW CREATE TABLE \`$t\`" | cut -f2- | sed -E 's/ AUTO_INCREMENT=[0-9]+//'
    done
} > "$OUT"

if [ "${1:-}" = "--check" ]; then
    REF="${2:-}"
    [ -f "$REF" ] || fail "référence absente : ${REF:-<fichier>}"
    if diff -u --label "référence ($REF)" --label "cette base ($DB)" "$REF" "$OUT"; then
        echo "Schéma identique à la référence."
    else
        exit 1
    fi
else
    cat "$OUT"
fi
