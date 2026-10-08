#!/usr/bin/env bash
# Brings back the version that was running before the last update (scripts/aggiorna.sh remembers it in
# .immagine-precedente). Run it on the server, from the project folder. Run it twice and you are forward again.
#
# IMPORTANT: it only changes the application's code. The database keeps whatever the newer version's
# migrations did to it. That is fine when the migrations only added things (the usual case). If the newer
# version changed the data in a way the old code cannot read, restore the backup made just before the
# update instead:   ./scripts/ripristina.sh backups/piedinauti-<data-dell-aggiornamento>.dump
#
# The old images are downloaded again from ghcr.io if the server no longer has them.
#
# Usage:   ./scripts/rollback.sh
set -euo pipefail

cd "$(dirname "$0")/.." || exit 1

fallito() { echo "ERRORE: $*" >&2; exit 1; }
valore() { grep -E "^$1=" .env 2>/dev/null | tail -n 1 | cut -d= -f2- || true; }

[ -f .env ] || fallito "manca .env: questa non è la cartella di un server configurato"
[ -s .immagine-precedente ] || fallito "non c'è una versione precedente registrata (.immagine-precedente): nessun aggiornamento da annullare"

PRECEDENTE=$(tr -d '[:space:]' < .immagine-precedente)
ATTUALE=$(valore IMMAGINE_TAG)
[[ "$PRECEDENTE" =~ ^(sha-[0-9a-f]{7}|latest)$ ]] || fallito "il contenuto di .immagine-precedente non è una versione valida: «$PRECEDENTE»"

echo "Torno dalla versione ${ATTUALE:-?} alla $PRECEDENTE..."
IMMAGINE_TAG=$PRECEDENTE docker compose pull app web \
    || fallito "non riesco a scaricare la versione $PRECEDENTE (il server è collegato a ghcr.io? vedi docs/PRODUZIONE.md, passo 3)"

if grep -qE '^IMMAGINE_TAG=' .env; then
    sed -i.bak -E "s|^IMMAGINE_TAG=.*|IMMAGINE_TAG=$PRECEDENTE|" .env && rm -f .env.bak
else
    echo "IMMAGINE_TAG=$PRECEDENTE" >> .env
fi
[ -n "$ATTUALE" ] && [ "$ATTUALE" != "latest" ] && echo "$ATTUALE" > .immagine-precedente

docker compose up -d --remove-orphans --wait --wait-timeout 240
docker compose ps
echo "Fatto: ora gira la versione $PRECEDENTE. Se il sito non torna sano, guarda:  docker compose logs --tail 100 app web"
echo "ATTENZIONE: finché non aggiorni di nuovo, un  git pull  sul server non cambia la versione in funzione."
