#!/bin/sh
# Runs once, when the database volume is created for the first time (the postgres image executes
# everything in /docker-entrypoint-initdb.d at that moment only).
#
# The application must NOT connect as the superuser: a superuser skips row-level security, which is
# the barrier that keeps one city's data away from the others. So two roles exist:
#
#   POSTGRES_USER  the administrator (superuser): backups, restores, maintenance. Never used by Laravel.
#   APP_DB_USER    the application role: owns the database and its tables, cannot skip row-level security.
#
# Plain `sh` variables are used here; psql does the quoting of names and passwords itself.
set -e

: "${APP_DB_USER:?APP_DB_USER is not set}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD is not set}"

if [ "$APP_DB_USER" = "$POSTGRES_USER" ]; then
    echo "APP_DB_USER and POSTGRES_USER are the same role ($POSTGRES_USER): row-level security would not apply." >&2
    echo "Use a different DB_USERNAME for the application (see .env.example)." >&2
    exit 1
fi

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v app_user="$APP_DB_USER" -v app_password="$APP_DB_PASSWORD" -v db_name="$POSTGRES_DB" <<'SQL'
CREATE ROLE :"app_user" LOGIN PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
ALTER DATABASE :"db_name" OWNER TO :"app_user";
SQL

echo "Application role \"$APP_DB_USER\" created; it owns database \"$POSTGRES_DB\"."
