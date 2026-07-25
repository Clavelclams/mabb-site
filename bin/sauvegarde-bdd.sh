#!/usr/bin/env bash
# ============================================================================
# sauvegarde-bdd.sh — [Bloc 0, 13/07/2026] sauvegarde quotidienne de la base
# de prod. LE filet de sécurité du projet : toutes les données du club
# (joueuses, stats, convocations, cotisations) vivent dans cette base.
#
# CE QUE FAIT LE SCRIPT :
#   1. lit DATABASE_URL dans .env.local (jamais de mot de passe en dur ici —
#      ce fichier est commité, les secrets restent dans .env.local sur OVH) ;
#   2. mysqldump --single-transaction (dump cohérent SANS bloquer le site) ;
#   3. compresse et date le fichier dans var/backups/ ;
#   4. rotation : supprime les dumps de plus de 14 jours.
#
# INSTALLATION SUR OVH (une fois) — voir instruction/33_SAUVEGARDE_BDD.md :
#   - chmod +x bin/sauvegarde-bdd.sh
#   - Manager OVH → Hébergement → Tâches planifiées (cron) → tous les jours
#     à 04h00 → commande : /home/<login>/www/bin/sauvegarde-bdd.sh
#   - ⚠️ var/backups est sur le MÊME hébergement : télécharger une copie
#     ailleurs régulièrement (voir doc 33) — un backup sur la machine qui
#     brûle n'est pas un backup.
# ============================================================================
set -euo pipefail

# Racine du projet = le dossier parent de bin/ (le script marche d'où qu'on l'appelle).
DIR="$(cd "$(dirname "$0")/.." && pwd)"

# --- 1. Lire DATABASE_URL (format mysql://user:pass@host:port/base?options) ---
URL="$(grep -E '^DATABASE_URL=' "$DIR/.env.local" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
if [ -z "$URL" ]; then
  echo "ERREUR : DATABASE_URL introuvable dans $DIR/.env.local" >&2
  exit 1
fi

SANS_PROTO="${URL#mysql://}"
CREDS="${SANS_PROTO%%@*}"
RESTE="${SANS_PROTO#*@}"
DB_USER="${CREDS%%:*}"
DB_PASS="${CREDS#*:}"
HOSTPORT="${RESTE%%/*}"
DB_HOST="${HOSTPORT%%:*}"
DB_PORT="${HOSTPORT##*:}"
[ "$DB_PORT" = "$DB_HOST" ] && DB_PORT=3306   # pas de :port dans l'URL → défaut
APRES_HOTE="${RESTE#*/}"
DB_NAME="${APRES_HOTE%%\?*}"

# Décodage %XX du mot de passe (les mots de passe OVH contiennent souvent
# des caractères spéciaux encodés dans l'URL).
DB_PASS="$(printf '%b' "${DB_PASS//%/\\x}")"

# --- 2 & 3. Dump compressé, daté --------------------------------------------
OUT="$DIR/var/backups"
mkdir -p "$OUT"
STAMP="$(date +%Y-%m-%d_%H%M)"
FICHIER="$OUT/bdd_${DB_NAME}_${STAMP}.sql.gz"

# MYSQL_PWD : le mot de passe passe par l'environnement, PAS sur la ligne de
# commande (il serait visible dans `ps` par les autres process du mutualisé).
MYSQL_PWD="$DB_PASS" mysqldump \
  --single-transaction \
  --routines \
  --no-tablespaces \
  -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
  "$DB_NAME" | gzip > "$FICHIER"

# Garde-fou : un dump anormalement petit = probablement un échec silencieux.
TAILLE=$(wc -c < "$FICHIER")
if [ "$TAILLE" -lt 10240 ]; then
  echo "ALERTE : dump suspicieusement petit ($TAILLE octets) — à vérifier !" >&2
  exit 2
fi

# --- 4. Rotation : 14 jours de rétention ------------------------------------
find "$OUT" -name 'bdd_*.sql.gz' -mtime +14 -delete

echo "OK : $FICHIER ($TAILLE octets)"
