#!/usr/bin/env bash
# Creates the two configuration files of a production server, with random passwords and key:
#
#   .env       read by Docker Compose  (domain, ports, database credentials)
#   src/.env   read by Laravel         (application settings, database, mail)
#
# Usage:   ./scripts/crea-env-produzione.sh piedinauti.it [proprietario-github]
#
# The GitHub owner (user or organization, the first part of github.com/<owner>/<repository>) is taken from the
# "origin" remote of this clone when you do not give it: the server downloads the images that GitHub Actions
# publishes under that name on ghcr.io.
#
# It refuses to overwrite existing files. The mail settings are placeholders: edit src/.env with the
# credentials of your SMTP provider (see docs/PRODUZIONE.md), then run ./scripts/controlla-produzione.sh.
set -euo pipefail

cd "$(dirname "$0")/.."

fallito() { echo "ERRORE: $*" >&2; exit 1; }

DOMINIO=${1:-}
[[ "$DOMINIO" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] \
    || fallito "indica il dominio, per esempio:  $0 piedinauti.it"

PROPRIETARIO=${2:-}
if [ -z "$PROPRIETARIO" ] && ORIGINE=$(git remote get-url origin 2>/dev/null); then
    PROPRIETARIO=$(printf '%s' "$ORIGINE" | sed -E 's#^(git@[^:]+:|https?://([^/@]+@)?[^/]+/|ssh://[^/]+/)##; s#/.*##')
fi
PROPRIETARIO=$(printf '%s' "$PROPRIETARIO" | tr '[:upper:]' '[:lower:]')
[[ "$PROPRIETARIO" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]] \
    || fallito "non riesco a ricavare il proprietario GitHub: indicalo, per esempio:  $0 $DOMINIO tuo-utente-github"

[ ! -e .env ] || fallito ".env esiste già: non lo sovrascrivo (spostalo o cancellalo se vuoi ricominciare)"
[ ! -e src/.env ] || fallito "src/.env esiste già: non lo sovrascrivo"

segreto() { openssl rand -hex 24; }

DB_ADMIN_PASSWORD=$(segreto)
DB_PASSWORD=$(segreto)
APP_KEY="base64:$(openssl rand -base64 32)"

mkdir -p src
umask 077   # the files hold secrets: readable by the owner only

cat > .env <<ENV
# Read by Docker Compose (not by Laravel; Laravel uses src/.env). Created by scripts/crea-env-produzione.sh.
COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
COMPOSE_PROJECT_NAME=piedinauti

# The images are built by GitHub Actions and downloaded from ghcr.io/<GITHUB_PROPRIETARIO>/piedinauti-*.
# GITHUB_PROPRIETARIO is the lowercase GitHub user or organization that owns the repository.
# IMMAGINE_TAG is the running version: scripts/aggiorna.sh sets it (sha-<7 characters of the commit>).
GITHUB_PROPRIETARIO=${PROPRIETARIO}
IMMAGINE_TAG=latest

HTTP_PORT=80
HTTPS_PORT=443

# The public domain: Caddy obtains the HTTPS certificate for it by itself.
SITE_ADDRESS=${DOMINIO}

# Database, two roles on purpose (see docs/RLS.md): the administrator is for backups and maintenance,
# the application role is what Laravel uses.
DB_DATABASE=piedinauti
DB_ADMIN_USER=postgres
DB_ADMIN_PASSWORD=${DB_ADMIN_PASSWORD}
DB_USERNAME=piedinauti_app
DB_PASSWORD=${DB_PASSWORD}
ENV

cat > src/.env <<ENV
# Read by Laravel. Created by scripts/crea-env-produzione.sh.
APP_NAME=Piedinauti
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${DOMINIO}
APP_LOCALE=it
APP_FALLBACK_LOCALE=it
APP_FAKER_LOCALE=it_IT
APP_MAINTENANCE_DRIVER=file

# Logs go to the container output: "docker compose logs app".
LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=piedinauti
DB_USERNAME=piedinauti_app
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=database
CACHE_STORE=database

BCRYPT_ROUNDS=12

# --- MODIFICA: the SMTP server that sends the invitation emails -------------------------------------
# Use the credentials of your mail provider (see docs/PRODUZIONE.md). The values below are placeholders.
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=utente-smtp
MAIL_PASSWORD=password-smtp
MAIL_FROM_ADDRESS="noreply@${DOMINIO}"
MAIL_FROM_NAME="Piedinauti"
ENV

cat <<FINE
Creati .env e src/.env (leggibili solo da te) per ${DOMINIO}; immagini da ghcr.io/${PROPRIETARIO}/.

Ora:
  1. apri src/.env e sostituisci i valori MAIL_* con quelli del tuo provider di posta;
  2. conserva una copia dei due file in un posto sicuro (contengono le password e la chiave dell'app);
  3. esegui:  ./scripts/controlla-produzione.sh   e poi   ./scripts/aggiorna.sh
FINE
