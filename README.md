# Piedinauti

Applicazione web per la gestione di un piedibus. Backend Laravel, frontend Inertia + Vue,
database PostgreSQL, tutto in Docker. Vedi il documento di progetto per requisiti e architettura.

## Avvio in sviluppo

Requisiti: Docker con il plugin Compose, `make`, `openssl`.

```bash
make bootstrap   # una sola volta: crea il progetto Laravel in ./src e lo configura
make up          # avvia tutti i servizi
```

- Sito: http://localhost:8090
- Email di prova (Mailpit): http://localhost:8026
- Database: `127.0.0.1:5432` (credenziali nel file `.env`)

Comandi utili: `make shell`, `make test`, `make artisan ARGS="migrate"`, `make logs`, `make down`.

Se l'aggiornamento automatico della pagina (HMR di Vite) non si collega dal browser, in
`src/vite.config.ts` imposta `server.hmr.host` a `localhost`.

## Servizi

| Servizio | Ruolo |
| --- | --- |
| `web` | Caddy: file statici, HTTPS, inoltro a PHP |
| `app` | PHP-FPM con Laravel |
| `db` | PostgreSQL |
| `queue` | Worker delle code (email) |
| `scheduler` | Attività pianificate di Laravel |
| `vite` | Solo sviluppo: server Vite con hot reload |
| `mailpit` | Solo sviluppo: casella email di prova |

## Produzione

Il codice sta su GitHub; **GitHub Actions** (`.github/workflows/immagini.yml`) costruisce le immagini e le
pubblica su ghcr.io; il server fa `git pull` e le scarica con `./scripts/aggiorna.sh`. Guida completa, dal VPS
vuoto al primo amministratore, ai backup: [docs/PRODUZIONE.md](docs/PRODUZIONE.md).

```bash
# sul server, una volta: git clone, docker login ghcr.io, poi
./scripts/crea-env-produzione.sh piedinauti.it   # crea .env e src/.env (poi imposta MAIL_* in src/.env)
./scripts/controlla-produzione.sh                # controlli prima del primo avvio
./scripts/aggiorna.sh                            # primo avvio e ogni aggiornamento
```

Caddy ottiene e rinnova da solo i certificati HTTPS: servono i DNS del dominio già puntati sul server
e le porte 80 e 443 aperte.

## Stato

In sviluppo attivo. La configurazione di produzione è descritta in `docs/PRODUZIONE.md`.
