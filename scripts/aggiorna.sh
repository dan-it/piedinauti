#!/usr/bin/env bash
# Updates the production server to the latest code on GitHub. Run it on the server, from the project folder.
#
#   1. git pull (fast-forward only);
#   2. waits for / checks that GitHub Actions has published the images of that commit on ghcr.io;
#   3. makes a backup if the site is running (if the backup fails, nothing is updated);
#   4. downloads the new images, starts them and waits until they are healthy
#      (database migrations run by themselves when the "app" container starts);
#   5. remembers the previous version so that scripts/rollback.sh can bring it back.
#
# Usage:   ./scripts/aggiorna.sh            update to the latest commit of the current branch
#          ./scripts/aggiorna.sh --forza    redo the update even if this commit is already running
set -euo pipefail

cd "$(dirname "$0")/.." || exit 1

fallito() { echo "ERRORE: $*" >&2; exit 1; }
passo() { printf '\n==> %s\n' "$*"; }
valore() { grep -E "^$1=" .env 2>/dev/null | tail -n 1 | cut -d= -f2- || true; }
# Sets KEY=value in .env (replaces the line if it exists, appends it otherwise).
imposta() {
    if grep -qE "^$1=" .env; then
        sed -i.bak -E "s|^$1=.*|$1=$2|" .env && rm -f .env.bak
    else
        printf '%s=%s\n' "$1" "$2" >> .env
    fi
}

FORZA=0
[ "${1:-}" = "--forza" ] && FORZA=1

for comando in git docker curl; do
    command -v "$comando" >/dev/null 2>&1 || fallito "serve «$comando» su questo server"
done
[ -d .git ] || fallito "questa cartella non è un clone di git: vedi docs/PRODUZIONE.md, passo 3"
[ -f .env ] && [ -f src/.env ] || fallito "mancano .env e/o src/.env: esegui prima  ./scripts/crea-env-produzione.sh tuodominio.it"

PROPRIETARIO=$(valore GITHUB_PROPRIETARIO)
[ -n "$PROPRIETARIO" ] || fallito "in .env manca GITHUB_PROPRIETARIO (l'utente o l'organizzazione GitHub del repository, in minuscolo)"
IMMAGINE_APP="ghcr.io/$PROPRIETARIO/piedinauti-app"
IMMAGINE_WEB="ghcr.io/$PROPRIETARIO/piedinauti-web"

passo "Scarico il codice da GitHub"
git pull --ff-only || fallito "git pull non è riuscito (modifiche locali o storia divergente?). Sul server non si modifica il codice: guarda  git status"
NUOVO="sha-$(git rev-parse HEAD | cut -c1-7)"
ATTUALE=$(valore IMMAGINE_TAG)
echo "Versione attuale: ${ATTUALE:-nessuna}.  Versione da installare: $NUOVO"

# Is something already running with this version?
IN_FUNZIONE=$(docker compose ps --status running -q app 2>/dev/null | wc -l | tr -dc '0-9')
if [ "$NUOVO" = "$ATTUALE" ] && [ "${IN_FUNZIONE:-0}" -gt 0 ] && [ "$FORZA" -eq 0 ]; then
    echo "Il sito è già alla versione $NUOVO. Niente da fare (con --forza si rifà comunque)."
    exit 0
fi

passo "Controllo che le immagini di $NUOVO siano pronte su ghcr.io"
mancano=0
for immagine in "$IMMAGINE_APP" "$IMMAGINE_WEB"; do
    docker manifest inspect "$immagine:$NUOVO" >/dev/null 2>&1 || mancano=1
done
if [ "$mancano" -eq 1 ]; then
    cat >&2 <<FINE
ERRORE: le immagini $NUOVO non sono (ancora) su ghcr.io.
  - GitHub Actions le sta ancora costruendo? Controlla la scheda «Actions» del repository e riprova a lavoro finito
    (di solito 5-10 minuti dopo il push).
  - Il server non è collegato a ghcr.io? Esegui una volta (docs/PRODUZIONE.md, passo 3):
        echo IL_TOKEN | docker login ghcr.io -u TUO_UTENTE_GITHUB --password-stdin
  - GITHUB_PROPRIETARIO in .env ($PROPRIETARIO) è scritto come il proprietario del repository, in minuscolo?
FINE
    exit 1
fi

if [ "${IN_FUNZIONE:-0}" -gt 0 ]; then
    passo "Il sito è in funzione: faccio un backup prima di aggiornare"
    ./scripts/backup.sh || fallito "il backup non è riuscito: non aggiorno. Correggi il problema e riprova"
fi

passo "Scarico le nuove immagini"
IMMAGINE_TAG=$NUOVO docker compose pull app web || fallito "download non riuscito: la versione in funzione non è stata toccata"

passo "Avvio la versione $NUOVO (aspetto che sia in salute: le migrazioni partono da sole)"
[ -n "$ATTUALE" ] && [ "$ATTUALE" != "latest" ] && [ "$ATTUALE" != "$NUOVO" ] && echo "$ATTUALE" > .immagine-precedente
imposta IMMAGINE_TAG "$NUOVO"
if ! docker compose up -d --remove-orphans --wait --wait-timeout 240; then
    cat >&2 <<FINE

ERRORE: la nuova versione non è diventata sana entro 4 minuti.
  Guarda:                 docker compose logs --tail 100 app
  Per tornare indietro:   ./scripts/rollback.sh
FINE
    exit 1
fi
docker compose ps
docker image prune -f >/dev/null 2>&1 || true

passo "Controllo finale"
DOMINIO=$(valore SITE_ADDRESS)
if curl -fsS -m 20 -o /dev/null -w "https://$DOMINIO/up  ->  HTTP %{http_code}\n" "https://$DOMINIO/up"; then
    echo; echo "Versione $NUOVO in linea."
else
    echo; echo "ATTENZIONE: i servizi sono avviati ma il sito non risponde ancora su HTTPS."
    echo "Se è il primo avvio il certificato può richiedere un minuto: guarda  docker compose logs web"
fi
echo "Per tornare alla versione precedente:  ./scripts/rollback.sh"
