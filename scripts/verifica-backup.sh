#!/usr/bin/env bash
# Proves that a backup can really be restored: restores it into a temporary database, checks that the data
# and the row-level security rules are there, and drops the temporary database. The real database is never
# touched. Do it after the first backup and then now and then (once a month is a good habit): a backup that
# was never restored is only a hope.
#
# Usage:   ./scripts/verifica-backup.sh                 (the newest file in backups/)
#          ./scripts/verifica-backup.sh backups/xxx.dump
#          make verifica-backup
set -euo pipefail

cd "$(dirname "$0")/.." || exit 1

DB_EXEC=${DB_EXEC:-docker compose exec -T db}

fallito() { echo "ERRORE: $*" >&2; exit 1; }
leggi() { grep -E "^$1=" .env 2>/dev/null | tail -n 1 | cut -d= -f2- || true; }

[ -f .env ] || fallito "manca .env: eseguilo dalla cartella del progetto"
ADMIN=$(leggi DB_ADMIN_USER); ADMIN=${ADMIN:-$(leggi DB_USERNAME)}
APP=$(leggi DB_USERNAME)

FILE=${1:-}
if [ -z "$FILE" ]; then
    # shellcheck disable=SC2012  # file names here are ours (timestamps): ls ordering is fine
    FILE=$(ls -1t "${BACKUP_DIR:-backups}"/*.dump 2>/dev/null | head -n 1 || true)
fi
[ -n "$FILE" ] && [ -s "$FILE" ] || fallito "nessun backup da verificare (cartella: ${BACKUP_DIR:-backups})"

PROVA="verifica_$(date +%Y%m%d%H%M%S)"
echo "Verifico: $FILE ($(du -h "$FILE" | cut -f1)), $(date -r "$FILE" '+%F %T')"

pulisci() {
    # psql does not substitute variables inside -c, so the SQL goes in through standard input.
    # shellcheck disable=SC2086
    $DB_EXEC psql -U "$ADMIN" -d postgres -X -q -v prova="$PROVA" >/dev/null 2>&1 <<'SQL' || true
DROP DATABASE IF EXISTS :"prova" WITH (FORCE);
SQL
}
trap pulisci EXIT

# shellcheck disable=SC2086
$DB_EXEC psql -U "$ADMIN" -d postgres -v ON_ERROR_STOP=1 -X -q -v prova="$PROVA" -v app="$APP" <<'SQL' \
    || fallito "non riesco a creare il database temporaneo"
CREATE DATABASE :"prova" OWNER :"app";
SQL

# shellcheck disable=SC2086
$DB_EXEC pg_restore -U "$ADMIN" -d "$PROVA" --no-owner --role="$APP" --exit-on-error < "$FILE" \
    || fallito "IL BACKUP NON SI RIPRISTINA: non è affidabile"

# shellcheck disable=SC2086
sql() { $DB_EXEC psql -U "$ADMIN" -d "$PROVA" -Atq -c "$1"; }

MIGRAZIONI=$(sql "select count(*) from migrations")
[ "$MIGRAZIONI" -gt 0 ] || fallito "il backup si ripristina ma la tabella delle migrazioni è vuota: il database sembra vuoto"

echo "Ripristino riuscito. Contenuto:"
for t in citta users bambini linee fermate presenze arrivi; do
    N=$(sql "select count(*) from $t" 2>/dev/null || echo "-")
    printf '    %-10s %s righe\n' "$t" "$N"
done

REGOLE=$(sql "select count(*) from pg_policies where schemaname='public'" 2>/dev/null || echo 0)
FORZATE=$(sql "select count(*) from pg_class where relkind='r' and relnamespace='public'::regnamespace and relforcerowsecurity" 2>/dev/null || echo 0)
PROPRIETARIO=$(sql "select count(*) from pg_tables where schemaname='public' and tableowner<>'$APP'")
echo "    regole di sicurezza a livello di riga: $REGOLE su $FORZATE tabelle"
[ "$PROPRIETARIO" = "0" ] || fallito "$PROPRIETARIO tabelle ripristinate non appartengono al ruolo dell'applicazione ($APP)"
if [ "$REGOLE" = "0" ]; then
    echo "    ATTENZIONE: nel backup non ci sono regole di sicurezza a livello di riga (normale solo per backup anteriori allo zip 0025)"
fi

echo "OK: il backup è ripristinabile."
