#!/usr/bin/env bash
#
# Export des données de production du PORTABLE, pour la bascule vers le VPS
# (docs/deploiement.md, étape 6). Lecture seule sur la base.
#
# Produit, dans un dossier daté :
#   production.sql.gz  données seules (pas de schéma : celui du VPS vient de ses
#                      migrations) des six tables, dans l'ordre des clés étrangères
#   manifest.json      effectifs et identifiants maximaux, effectifs du journal,
#                      comparés sur le VPS par import-production.sh
#   SHA256SUMS         contrôle d'intégrité après copie
#
# Refuse de tourner si le portable collecte encore (PIPELINE_ENABLED=true) ou si
# un passage est en cours : le dump ne serait déjà plus le dernier état.
#
# Usage : scripts/export-production.sh [dossier de sortie]

set -euo pipefail

cd "$(dirname "$0")/.."

# Ordre des clés étrangères : prediction_log pointe sur odds_movements et matches,
# odds_movements, advanced_data et predictions pointent sur matches.
TABLES=(matches odds_movements advanced_data predictions prediction_log pipeline_runs)

fail() { echo "ÉCHEC : $*" >&2; exit 1; }

# 1. Le portable ne doit plus collecter
if [ "$(php artisan tinker --execute='echo config("pipeline.enabled") === true ? "on" : "off";' 2>/dev/null | tail -n 1)" != "off" ]; then
    fail "collecte active ou état illisible (PIPELINE_ENABLED=true dans le .env du portable ?). Le mettre à false (ou retirer la ligne) avant le dump final : sinon un passage pourrait écrire après l'export."
fi
if pgrep -f "artisan (pipeline:daily|pipeline:run|market:track|schedule:run|schedule:work)" >/dev/null; then
    fail "un passage du pipeline ou le planificateur tourne sur le portable. Attendre sa fin, puis relancer."
fi

OUT="${1:-storage/app/private/export/$(date -u +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT

DB="$(php artisan db:client-options "$CNF")"
[ -n "$DB" ] || fail "nom de base introuvable (db:client-options)"

# 2. Données seules, identifiants conservés. mysqldump écrit les tables dans l'ordre
#    des arguments et désactive FOREIGN_KEY_CHECKS en tête de fichier.
#
#    --skip-tz-utc : les heures sortent telles que Laravel les lit sur le portable.
#    Laravel y écrit de l'UTC, mais la session MariaDB du portable est en EAT
#    (UTC+3) : une colonne TIMESTAMP stocke donc « 17:00 EAT ». Sans cette option,
#    mysqldump convertirait en UTC réel (14:00) et le VPS lirait toutes les heures
#    du journal décalées de 3 h, alors que les DATETIME (match_date) ne le seraient
#    pas. Constaté le 26/09/2026 sur une répétition de l'export.
echo "Dump de ${DB} : ${TABLES[*]}"
mysqldump --defaults-extra-file="$CNF" \
    --single-transaction --no-create-info --complete-insert --skip-triggers --skip-tz-utc \
    --hex-blob --no-tablespaces --default-character-set=utf8mb4 \
    "$DB" "${TABLES[@]}" > "$OUT/production.sql"

tail -n 1 "$OUT/production.sql" | grep -q "Dump completed" || fail "dump incomplet ($OUT/production.sql)"
gzip -f "$OUT/production.sql"

# 3. Manifeste, juste après le dump (rien ne tourne : vérifié plus haut)
php artisan data:manifest > "$OUT/manifest.json"

(cd "$OUT" && sha256sum production.sql.gz manifest.json > SHA256SUMS)

echo
echo "Export prêt dans $OUT :"
ls -l "$OUT"
echo
echo "Journal exporté :"
grep -A6 '"journal"' "$OUT/manifest.json"
echo
echo "Copie vers le VPS (depuis ce dossier) :"
echo "  scp -r $OUT deploy@<VPS>:/home/deploy/transfert/"
