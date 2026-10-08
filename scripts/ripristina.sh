#!/usr/bin/env bash
# Restores a backup, REPLACING the current database.
#
# Order of work: asks for confirmation, makes a safety backup of what is there now, stops the application
# services, recreates the database (owned by the application role), restores the backup, starts everything
# again. The administrator role does the restore; the application role ends up owning the data, and the
# row-level security rules come back with the dump.
#
# Usage:   ./scripts/ripristina.sh backups/piedinauti-20261006-033000.dump     (or: make ripristina FILE=...)
#          ./scripts/ripristina.sh --si FILE      (no confirmation question: for scripts)
set -euo pipefail

cd "$(dirname "$0")/.." || exit 1

DB_EXEC=${DB_EXEC:-docker compose exec -T db}
DC=${DC:-docker compose}

fallito() { echo "ERRORE: $*" >&2; exit 1; }
leggi() { grep -E "^$1=" .env 2>/dev/null | tail -n 1 | cut -d= -f2- || true; }

CONFERMATO=no
if [ "${1:-}" = "--si" ]; then CONFERMATO=si; shift; fi
FILE=${1:-}
[ -n "$FILE" ] || fallito "indica il file da ripristinare, per esempio:  $0 backups/piedinauti-20261006-033000.dump"
[ -s "$FILE" ] || fallito "il file «$FILE» non esiste o è vuoto"
[ -f .env ] || fallito "manca .env: eseguilo dalla cartella del progetto"

ADMIN=$(leggi DB_ADMIN_USER); ADMIN=${ADMIN:-$(leggi DB_USERNAME)}
APP=$(leggi DB_USERNAME)
DB=$(leggi DB_DATABASE); DB=${DB:-piedinauti}
[ "$ADMIN" != "$APP" ] || fallito "DB_ADMIN_USER e DB_USERNAME coincidono: l'installazione non usa i due ruoli (vedi docs/RLS.md)"

# The dump must be readable before anything is touched.
# shellcheck disable=SC2086
$DB_EXEC pg_restore -l < "$FILE" > /dev/null || fallito "«$FILE» non è un backup leggibile: non tocco nulla"

echo "Sto per SOSTITUIRE il database «$DB» con il contenuto di:"
echo "    $FILE ($(du -h "$FILE" | cut -f1))"
echo "I dati inseriti dopo quel backup andranno persi (una copia di sicurezza di quelli attuali viene fatta prima)."
if [ "$CONFERMATO" != "si" ]; then
    printf 'Per continuare scrivi il nome del database (%s): ' "$DB"
    read -r RISPOSTA
    [ "$RISPOSTA" = "$DB" ] || fallito "risposta diversa: annullato, non ho toccato nulla"
fi

echo "==> Copia di sicurezza dello stato attuale"
BACKUP_PREFISSO=prima-del-ripristino ./scripts/backup.sh || fallito "non riesco a fare la copia di sicurezza: mi fermo senza toccare nulla"

echo "==> Fermo l'applicazione"
$DC stop web app queue scheduler || true

echo "==> Ricreo il database vuoto"
# shellcheck disable=SC2086
$DB_EXEC psql -U "$ADMIN" -d postgres -v ON_ERROR_STOP=1 -X -q -v db="$DB" -v app="$APP" <<'SQL'
DROP DATABASE IF EXISTS :"db" WITH (FORCE);
CREATE DATABASE :"db" OWNER :"app";
SQL

echo "==> Ripristino il backup"
# shellcheck disable=SC2086
$DB_EXEC pg_restore -U "$ADMIN" -d "$DB" --no-owner --role="$APP" --exit-on-error < "$FILE" \
    || fallito "il ripristino non è riuscito a metà: il database potrebbe essere incompleto. Rifai il ripristino con questo stesso file, oppure con la copia «prima-del-ripristino» in backups/"

TABELLE=$($DB_EXEC psql -U "$ADMIN" -d "$DB" -Atc "select count(*) from pg_tables where schemaname='public'")
[ "$TABELLE" -gt 0 ] || fallito "dopo il ripristino il database non ha tabelle"
echo "    $TABELLE tabelle ripristinate"

echo "==> Riavvio l'applicazione"
$DC up -d

echo "Fatto."
