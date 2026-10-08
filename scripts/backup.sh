#!/usr/bin/env bash
# Backup of the database to backups/piedinauti-YYYYMMDD-HHMMSS.dump (PostgreSQL custom format: compressed,
# restorable with scripts/ripristina.sh). Made by the database administrator role, which sees every city's
# rows: row-level security never hides data from a backup.
#
# The file is checked (non-empty and readable by pg_restore) before it is kept, and files older than
# BACKUP_GIORNI days (default 14) are removed.
#
# Optional, set in the environment or in the crontab line:
#   BACKUP_DIR       where to write (default: backups)
#   BACKUP_GIORNI    how many days to keep (default: 14)
#   BACKUP_PREFISSO  file name prefix (default: piedinauti)
#   BACKUP_REMOTE    copy each backup to another machine too, for example  utente@altro-server:/backups/piedinauti/
#                    (needs ssh access without a password, and rsync or scp on both sides)
#
# Usage:   ./scripts/backup.sh          (or: make backup)
# Daily:   30 3 * * * cd /srv/piedinauti && ./scripts/backup.sh >> backups/backup.log 2>&1
set -euo pipefail

cd "$(dirname "$0")/.." || exit 1

# How to reach the database tools (overridable for tests of this script on a plain PostgreSQL).
DB_EXEC=${DB_EXEC:-docker compose exec -T db}

fallito() { echo "$(date '+%F %T') ERRORE: $*" >&2; exit 1; }
leggi() { grep -E "^$1=" .env 2>/dev/null | tail -n 1 | cut -d= -f2- || true; }

[ -f .env ] || fallito "manca .env: eseguilo dalla cartella del progetto"
ADMIN=$(leggi DB_ADMIN_USER); ADMIN=${ADMIN:-$(leggi DB_USERNAME)}
DB=$(leggi DB_DATABASE); DB=${DB:-piedinauti}

DEST=${BACKUP_DIR:-backups}
GIORNI=${BACKUP_GIORNI:-14}
PREFISSO=${BACKUP_PREFISSO:-piedinauti}
[[ "$GIORNI" =~ ^[0-9]+$ ]] || fallito "BACKUP_GIORNI deve essere un numero (ricevuto: $GIORNI)"

mkdir -p "$DEST"
chmod 700 "$DEST"   # the dumps hold children's personal data

FILE="$DEST/$PREFISSO-$(date +%Y%m%d-%H%M%S).dump"
TMP="$FILE.parziale"
trap 'rm -f "$TMP"' EXIT

# shellcheck disable=SC2086  # DB_EXEC is a command with arguments on purpose
$DB_EXEC pg_dump -U "$ADMIN" -Fc "$DB" > "$TMP" || fallito "pg_dump non è riuscito"

[ -s "$TMP" ] || fallito "il backup è vuoto"
# shellcheck disable=SC2086
$DB_EXEC pg_restore -l < "$TMP" > /dev/null || fallito "il backup non è leggibile da pg_restore"

chmod 600 "$TMP"
mv "$TMP" "$FILE"
trap - EXIT

# Old backups (only this prefix's files, and only .dump).
find "$DEST" -maxdepth 1 -name "$PREFISSO-*.dump" -mtime "+$GIORNI" -delete

DIMENSIONE=$(du -h "$FILE" | cut -f1)
echo "$(date '+%F %T') backup ok: $FILE ($DIMENSIONE), conservati $(find "$DEST" -maxdepth 1 -name "$PREFISSO-*.dump" | wc -l) file"

# A copy on another machine: a backup that lives only on the server it protects is not a backup.
if [ -n "${BACKUP_REMOTE:-}" ]; then
    if command -v rsync >/dev/null 2>&1; then
        rsync -a "$FILE" "$BACKUP_REMOTE" || fallito "copia su $BACKUP_REMOTE non riuscita (il backup locale c'è)"
    else
        scp -q "$FILE" "$BACKUP_REMOTE" || fallito "copia su $BACKUP_REMOTE non riuscita (il backup locale c'è)"
    fi
    echo "$(date '+%F %T') copia remota ok: $BACKUP_REMOTE"
fi
