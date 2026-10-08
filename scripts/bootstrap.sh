#!/usr/bin/env bash
# One-time project setup: creates the Laravel application inside ./src using
# the development container, then configures it for this stack
# (PostgreSQL, database-backed queue/session/cache, Italian locale).
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -f src/artisan ]; then
    echo "src/ already contains a Laravel project: nothing to do."
    exit 0
fi

# Create the Compose environment file with the current user's ids and a random DB password.
if [ ! -f .env ]; then
    cp .env.example .env
    sed -i "s/^HOST_UID=.*/HOST_UID=$(id -u)/; s/^HOST_GID=.*/HOST_GID=$(id -g)/" .env
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$(openssl rand -hex 16)/" .env
    sed -i "s/^DB_ADMIN_PASSWORD=.*/DB_ADMIN_PASSWORD=$(openssl rand -hex 16)/" .env
    echo "Created .env"
fi

# Read a variable from the Compose .env file.
env_value() {
    grep -E "^$1=" .env | head -n 1 | cut -d= -f2-
}

# Set (or add) a variable in the Laravel environment file.
set_env() {
    local key="$1" value="$2" file="src/.env"
    if grep -qE "^#?[[:space:]]*${key}=" "$file"; then
        sed -i -E "s|^#?[[:space:]]*${key}=.*|${key}=${value}|" "$file"
    else
        echo "${key}=${value}" >> "$file"
    fi
}

# Run a one-off command inside the app container, without starting dependencies.
run() {
    docker compose run --rm --no-deps -T app "$@"
}

mkdir -p src

echo "==> Building the development image"
docker compose build app

echo "==> Creating the Laravel project (Inertia + Vue starter kit)"
run composer create-project laravel/vue-starter-kit . --no-interaction --no-scripts --prefer-dist
run php -r "copy('.env.example', '.env');"
run php artisan key:generate --ansi
run php artisan package:discover --ansi

echo "==> Configuring the application"
set_env APP_NAME "Piedinauti"
set_env APP_URL "http://localhost:$(env_value HTTP_PORT)"
set_env APP_LOCALE it
set_env APP_FALLBACK_LOCALE it
set_env APP_FAKER_LOCALE it_IT
set_env DB_CONNECTION pgsql
set_env DB_HOST db
set_env DB_PORT 5432
set_env DB_DATABASE "$(env_value DB_DATABASE)"
set_env DB_USERNAME "$(env_value DB_USERNAME)"
set_env DB_PASSWORD "$(env_value DB_PASSWORD)"
set_env SESSION_DRIVER database
set_env QUEUE_CONNECTION database
set_env CACHE_STORE database
set_env MAIL_MAILER smtp
set_env MAIL_HOST mailpit
set_env MAIL_PORT 1025
set_env MAIL_FROM_ADDRESS "noreply@piedinauti.local"
set_env MAIL_FROM_NAME "Piedinauti"

# Attendance dates are local dates, so the application runs in Italian time.
sed -i "s/'timezone' => 'UTC'/'timezone' => 'Europe\/Rome'/" src/config/app.php

echo "==> Installing Italian translations"
run composer require laravel-lang/common --no-interaction
run php artisan lang:add it

echo "==> Installing frontend dependencies"
run npm install

echo "==> Starting the database and running migrations"
docker compose up -d --wait db
run php artisan migrate --force

echo
echo "Done. Start everything with:  make up"
echo "Site:     http://localhost:$(env_value HTTP_PORT)"
echo "Mailpit:  http://localhost:8026"
