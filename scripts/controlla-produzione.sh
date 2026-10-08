#!/usr/bin/env bash
# Checks a server before the first launch (and after changes): configuration, DNS, ports, Docker.
# Prints one line per check: OK, ATTENZIONE (worth a look) or ERRORE (must be fixed). Exit status 1 on errors.
#
# Usage:   ./scripts/controlla-produzione.sh
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

ERRORI=0
ATTENZIONI=0
ok() { printf '  \033[32mOK\033[0m          %s\n' "$*"; }
attenzione() { printf '  \033[33mATTENZIONE\033[0m  %s\n' "$*"; ATTENZIONI=$((ATTENZIONI + 1)); }
errore() { printf '  \033[31mERRORE\033[0m      %s\n' "$*"; ERRORI=$((ERRORI + 1)); }

# Value of KEY in a KEY=value file (last definition wins; surrounding quotes removed).
valore() { grep -E "^$2=" "$1" 2>/dev/null | tail -n 1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

echo "File di configurazione"
if [ ! -f .env ] || [ ! -f src/.env ]; then
    errore ".env e/o src/.env mancano: esegui  ./scripts/crea-env-produzione.sh tuodominio.it"
    echo; echo "Non posso proseguire senza i file di configurazione."; exit 1
fi
ok ".env e src/.env esistono"

for f in .env src/.env; do
    permessi=$(stat -c '%a' "$f" 2>/dev/null || stat -f '%Lp' "$f")
    if [ "$permessi" = "600" ]; then ok "$f è leggibile solo dal proprietario"; else attenzione "$f ha permessi $permessi: esegui  chmod 600 $f"; fi
done

DOMINIO=$(valore .env SITE_ADDRESS)
case "$(valore .env COMPOSE_FILE)" in
    *docker-compose.prod.yml*) ok "COMPOSE_FILE usa la configurazione di produzione" ;;
    *) errore "COMPOSE_FILE in .env non include docker-compose.prod.yml" ;;
esac

if [[ "$DOMINIO" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]]; then ok "SITE_ADDRESS è un dominio ($DOMINIO)"; else errore "SITE_ADDRESS in .env deve essere il dominio pubblico (ora: '$DOMINIO')"; fi
[ "$(valore src/.env APP_URL)" = "https://$DOMINIO" ] && ok "APP_URL è https://$DOMINIO" || errore "APP_URL in src/.env deve essere https://$DOMINIO (ora: '$(valore src/.env APP_URL)')"
[ "$(valore src/.env APP_ENV)" = "production" ] && ok "APP_ENV=production" || errore "APP_ENV in src/.env deve essere production"
[ "$(valore src/.env APP_DEBUG)" = "false" ] && ok "APP_DEBUG=false" || errore "APP_DEBUG in src/.env deve essere false (con true il sito mostra dettagli interni)"
case "$(valore src/.env APP_KEY)" in base64:?*) ok "APP_KEY è impostata" ;; *) errore "APP_KEY in src/.env manca o non è valida" ;; esac
[ "$(valore src/.env SESSION_SECURE_COOKIE)" = "true" ] && ok "i cookie di sessione sono solo HTTPS" || attenzione "SESSION_SECURE_COOKIE in src/.env dovrebbe essere true"

PROPRIETARIO=$(valore .env GITHUB_PROPRIETARIO)
if [[ "$PROPRIETARIO" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]]; then ok "le immagini vengono da ghcr.io/$PROPRIETARIO/"; else errore "GITHUB_PROPRIETARIO in .env deve essere l'utente o l'organizzazione GitHub del repository, in minuscolo (ora: '$PROPRIETARIO')"; fi
if [ -d .git ]; then ok "questa cartella è un clone di git (si aggiorna con scripts/aggiorna.sh)"; else attenzione "questa cartella non è un clone di git: scripts/aggiorna.sh non potrà fare git pull"; fi

echo; echo "Database"
APP_UTENTE=$(valore .env DB_USERNAME); ADMIN_UTENTE=$(valore .env DB_ADMIN_USER)
if [ -n "$APP_UTENTE" ] && [ "$APP_UTENTE" != "$ADMIN_UTENTE" ]; then ok "ruolo dell'applicazione ($APP_UTENTE) diverso dall'amministratore ($ADMIN_UTENTE)"; else errore "DB_USERNAME e DB_ADMIN_USER devono essere ruoli diversi: l'applicazione non deve essere superutente (vedi docs/RLS.md)"; fi
[ "$(valore src/.env DB_USERNAME)" = "$APP_UTENTE" ] && [ "$(valore src/.env DB_PASSWORD)" = "$(valore .env DB_PASSWORD)" ] \
    && ok "src/.env e .env usano le stesse credenziali dell'applicazione" || errore "DB_USERNAME/DB_PASSWORD in src/.env devono coincidere con quelli di .env"
for c in DB_PASSWORD DB_ADMIN_PASSWORD; do
    v=$(valore .env "$c")
    if [ "${#v}" -ge 16 ] && [[ "$v" != *change-me* ]]; then ok "$c è una password robusta"; else errore "$c in .env è troppo corta o è ancora quella di esempio"; fi
done

echo; echo "Posta (inviti e reimpostazione password)"
MAIL_HOST=$(valore src/.env MAIL_HOST)
case "$MAIL_HOST" in
    ""|*example.com|mailpit|localhost|127.0.0.1) errore "MAIL_HOST in src/.env è ancora un segnaposto ('$MAIL_HOST'): senza posta non partono gli inviti" ;;
    *) ok "MAIL_HOST è $MAIL_HOST" ;;
esac
case "$(valore src/.env MAIL_PASSWORD)" in password-smtp|"") attenzione "MAIL_PASSWORD in src/.env è vuota o di esempio (va bene solo se il tuo server SMTP non chiede autenticazione)" ;; *) ok "MAIL_PASSWORD è impostata" ;; esac
[[ "$(valore src/.env MAIL_FROM_ADDRESS)" == *"@$DOMINIO" ]] && ok "il mittente delle email è del dominio ($DOMINIO)" || attenzione "MAIL_FROM_ADDRESS non è un indirizzo di $DOMINIO: i filtri antispam potrebbero rifiutare le email"

echo; echo "Rete"
if command -v getent >/dev/null 2>&1 && [ -n "$DOMINIO" ]; then
    IP_DNS=$(getent ahostsv4 "$DOMINIO" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ')
    IP_SERVER=$(curl -4 -fsS -m 6 https://api.ipify.org 2>/dev/null || true)
    if [ -z "$IP_DNS" ]; then
        errore "il dominio $DOMINIO non risolve a nessun indirizzo: crea il record DNS di tipo A verso l'IP del server"
    elif [ -z "$IP_SERVER" ]; then
        attenzione "$DOMINIO risolve a $IP_DNS ma non riesco a conoscere l'IP pubblico di questo server: controllalo a mano"
    elif [ "$IP_DNS" = "$IP_SERVER " ]; then
        ok "$DOMINIO punta a questo server ($IP_SERVER)"
    else
        errore "$DOMINIO risolve a $IP_DNS, ma questo server ha l'IP $IP_SERVER: correggi il record DNS (e attendi che si propaghi)"
    fi
fi
if command -v ss >/dev/null 2>&1; then
    OCCUPATE=$(ss -ltnH '( sport = :80 or sport = :443 )' 2>/dev/null | awk '{print $4}' | tr '\n' ' ')
    if [ -z "$OCCUPATE" ]; then ok "le porte 80 e 443 sono libere"; else attenzione "porte già in ascolto: $OCCUPATE (normale se il sito è già avviato; altrimenti ferma l'altro servizio, per esempio apache o nginx)"; fi
fi

echo; echo "Docker e spazio"
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    ok "Docker con il plugin Compose è installato"
    if docker compose config -q 2>/dev/null; then ok "la configurazione Compose è valida"; else errore "docker compose config segnala errori: eseguilo per vedere quali"; fi
    if [ -n "$PROPRIETARIO" ] && docker manifest inspect "ghcr.io/$PROPRIETARIO/piedinauti-app:latest" >/dev/null 2>&1; then
        ok "le immagini su ghcr.io sono raggiungibili da questo server"
    else
        attenzione "non vedo le immagini ghcr.io/$PROPRIETARIO/piedinauti-app: Actions non ha ancora finito, oppure manca  docker login ghcr.io (docs/PRODUZIONE.md, passo 3)"
    fi
else
    errore "Docker con il plugin Compose non è installato (vedi docs/PRODUZIONE.md, passo 2)"
fi
LIBERO_GB=$(df -P --output=avail -BG . 2>/dev/null | tail -n 1 | tr -dc '0-9')
if [ -n "${LIBERO_GB:-}" ]; then
    if [ "$LIBERO_GB" -ge 5 ]; then ok "spazio libero: ${LIBERO_GB} GB"; else attenzione "spazio libero: ${LIBERO_GB} GB (ne servono almeno 5 per immagini e backup)"; fi
fi

echo
if [ "$ERRORI" -gt 0 ]; then
    printf '\033[31m%d errori\033[0m e %d avvisi: correggi gli errori prima di avviare il sito.\n' "$ERRORI" "$ATTENZIONI"; exit 1
fi
printf '\033[32mNessun errore\033[0m (%d avvisi). Puoi avviare il sito:  docker compose up -d\n' "$ATTENZIONI"
