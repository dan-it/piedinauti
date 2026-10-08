#!/usr/bin/env bash
# Converts an EXISTING installation to the two-role database model that row-level security needs.
#
# Before: the application connects as the database superuser (DB_USERNAME in .env). A superuser skips
#         row-level security, so the policies would exist but never apply.
# After:  the old superuser becomes the administrator (DB_ADMIN_USER: backups and maintenance only) and a
#         new, ordinary role (default: piedinauti_app) owns the database and is what Laravel uses.
#
# What it does, in order:
#   1. makes a backup of the database (backups/prima-di-rls-*.dump);
#   2. creates the application role and hands it the ownership of the database, tables and sequences;
#   3. recreates the test database (piedinauti_test) owned by the application role: it holds no real data;
#   4. rewrites .env and src/.env with the new credentials (originals kept as .env.prima-di-rls and src/.env.prima-di-rls).
#
# Nothing is deleted from the real database. Run it once, from the project folder:
#
#     ./scripts/passa-a-rls.sh            # application role named piedinauti_app
#     ./scripts/passa-a-rls.sh nome_ruolo # or choose another name
#
# Then:   make up   &&   make artisan ARGS="migrate"   &&   make test
#
# New installations do not need this: docker/db/init creates the two roles by itself.
set -euo pipefail

cd "$(dirname "$0")/.."

# How to reach the database tools. By default through the db container; overridable (used by the tests of
# this script itself, which run it against a plain PostgreSQL).
PSQL=${PSQL:-docker compose exec -T db psql}
PG_DUMP=${PG_DUMP:-docker compose exec -T db pg_dump}
SENZA_DOCKER=${SENZA_DOCKER:-}

fallito() { echo "ERRORE: $*" >&2; exit 1; }

[ -f .env ] || fallito "manca il file .env: eseguilo dalla cartella del progetto"
[ -f src/.env ] || fallito "manca src/.env"

# Reads VAR from a KEY=value file (last definition wins).
leggi() { grep -E "^$2=" "$1" | tail -n 1 | cut -d= -f2- || true; }

if grep -qE '^DB_ADMIN_USER=' .env; then
    echo "Questa installazione usa già due ruoli (DB_ADMIN_USER è nel file .env): niente da fare."
    exit 0
fi

DB=$(leggi .env DB_DATABASE); DB=${DB:-piedinauti}
VECCHIO_UTENTE=$(leggi .env DB_USERNAME)
VECCHIA_PASSWORD=$(leggi .env DB_PASSWORD)
[ -n "$VECCHIO_UTENTE" ] || fallito "DB_USERNAME non trovato in .env"

# The new docker-compose.yml asks for DB_ADMIN_PASSWORD, which .env does not have yet: until the files are
# rewritten at the end, give Compose the old (superuser) credentials through the environment.
export DB_ADMIN_USER="$VECCHIO_UTENTE" DB_ADMIN_PASSWORD="$VECCHIA_PASSWORD"

APP_UTENTE=${1:-piedinauti_app}
[[ "$APP_UTENTE" =~ ^[a-z_][a-z0-9_]*$ ]] || fallito "il nome del ruolo deve usare solo lettere minuscole, cifre e _ (ricevuto: $APP_UTENTE)"
[ "$APP_UTENTE" != "$VECCHIO_UTENTE" ] || fallito "il nuovo ruolo deve avere un nome diverso da quello attuale ($VECCHIO_UTENTE)"
APP_PASSWORD=$(openssl rand -hex 16)

sql() { $PSQL -U "$VECCHIO_UTENTE" -v ON_ERROR_STOP=1 -X -q "$@"; }

if [ -z "$SENZA_DOCKER" ]; then
    echo "==> Avvio il database"
    docker compose up -d --wait db
fi

echo "==> Controllo che «$VECCHIO_UTENTE» sia l'amministratore del database"
SUPER=$(sql -d postgres -Atc "select rolsuper from pg_roles where rolname = current_user")
[ "$SUPER" = "t" ] || fallito "«$VECCHIO_UTENTE» non è un superutente: questa installazione sembra già convertita o configurata a mano"

echo "==> Backup del database in backups/"
mkdir -p backups
COPIA="backups/prima-di-rls-$(date +%Y%m%d-%H%M%S).dump"
$PG_DUMP -U "$VECCHIO_UTENTE" -Fc "$DB" > "$COPIA"
[ -s "$COPIA" ] || fallito "il backup è vuoto: mi fermo senza toccare nulla"
echo "    $COPIA ($(du -h "$COPIA" | cut -f1))"

echo "==> Creo il ruolo «$APP_UTENTE» e gli passo la proprietà del database"
sql -d postgres -v app_user="$APP_UTENTE" -v app_password="$APP_PASSWORD" -v db_name="$DB" <<'SQL'
SELECT NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_user') AS nuovo \gset
\if :nuovo
    CREATE ROLE :"app_user" LOGIN PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
\else
    ALTER ROLE :"app_user" LOGIN PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
\endif
ALTER DATABASE :"db_name" OWNER TO :"app_user";
SQL

echo "==> Passo al nuovo ruolo la proprietà di tabelle, sequenze, viste e funzioni"
sql -d "$DB" -v app_user="$APP_UTENTE" <<'SQL'
SELECT format('ALTER TABLE public.%I OWNER TO %I', tablename, :'app_user') FROM pg_tables WHERE schemaname = 'public' \gexec
SELECT format('ALTER SEQUENCE public.%I OWNER TO %I', sequencename, :'app_user') FROM pg_sequences WHERE schemaname = 'public' \gexec
SELECT format('ALTER VIEW public.%I OWNER TO %I', viewname, :'app_user') FROM pg_views WHERE schemaname = 'public' \gexec
SELECT format('ALTER FUNCTION %s OWNER TO %I', p.oid::regprocedure, :'app_user')
  FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e') \gexec
GRANT ALL ON SCHEMA public TO :"app_user";
SQL

echo "==> Ricreo il database dei test («piedinauti_test»), che non contiene dati veri"
sql -d postgres -v app_user="$APP_UTENTE" <<'SQL'
DROP DATABASE IF EXISTS piedinauti_test WITH (FORCE);
CREATE DATABASE piedinauti_test OWNER :"app_user";
SQL

echo "==> Aggiorno .env e src/.env (copie degli originali: .env.prima-di-rls e src/.env.prima-di-rls)"
cp .env .env.prima-di-rls
cp src/.env src/.env.prima-di-rls

imposta() { # file chiave valore
    if grep -qE "^#?[[:space:]]*$2=" "$1"; then
        sed -i -E "s|^#?[[:space:]]*$2=.*|$2=$3|" "$1"
    else
        printf '%s=%s\n' "$2" "$3" >> "$1"
    fi
}
imposta .env DB_ADMIN_USER "$VECCHIO_UTENTE"
imposta .env DB_ADMIN_PASSWORD "$VECCHIA_PASSWORD"
imposta .env DB_USERNAME "$APP_UTENTE"
imposta .env DB_PASSWORD "$APP_PASSWORD"
imposta src/.env DB_USERNAME "$APP_UTENTE"
imposta src/.env DB_PASSWORD "$APP_PASSWORD"

if [ -z "$SENZA_DOCKER" ]; then
    echo "==> Riavvio i servizi con le nuove credenziali"
    docker compose up -d --force-recreate --wait db
    docker compose up -d
fi

cat <<FINE

Fatto.
  Amministratore (backup, manutenzione): $VECCHIO_UTENTE   (DB_ADMIN_USER in .env)
  Applicazione (Laravel):                $APP_UTENTE   (DB_USERNAME in .env e src/.env)
  Backup di sicurezza:                   $COPIA

Ora applica la sicurezza a livello di riga e prova:
    make artisan ARGS="migrate"
    make test
FINE
