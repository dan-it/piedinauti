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

1. Copia `.env.example` in `.env` e imposta `COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml`,
   `HTTP_PORT=80`, `HTTPS_PORT=443`, `SITE_ADDRESS` (il dominio) e una `DB_PASSWORD` robusta.
2. Prepara `src/.env` con `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY` e le
   impostazioni SMTP reali (le stesse credenziali del database di `.env`).
3. `docker compose up -d --build`: le migrazioni partono da sole all'avvio del servizio `app`.

Caddy ottiene e rinnova da solo i certificati HTTPS: servono i DNS del dominio già puntati sul server
e le porte 80 e 443 aperte.

## Stato

Fase 0 (preparazione). Questa configurazione non è ancora stata provata su un host Docker reale:
il primo `make bootstrap` è il collaudo.
