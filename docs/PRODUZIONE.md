# Messa in produzione di Piedinauti

Guida passo per passo per portare il sito su un VPS con il dominio **piedinauti.it**, con HTTPS
automatico, backup e aggiornamenti. Il codice sta su **GitHub**: le immagini Docker le costruisce GitHub Actions,
il server fa `git pull` e le scarica (non compila nulla). Per un altro dominio basta sostituirlo.

**Tempo:** circa un'ora, più l'attesa della propagazione del DNS. **Costo extra:** nessuno oltre al VPS e al
dominio (il certificato HTTPS è gratuito, Let's Encrypt, e Caddy lo ottiene e lo rinnova da solo).

> Tutto ciò che segue presuppone un server **Ubuntu 22.04/24.04 o Debian 12** con accesso `root` (o `sudo`)
> e un indirizzo IP pubblico. Per i comandi sul server: `#` = come root, `$` = come utente normale.

---

## 0. Cosa serve, prima di cominciare

| Cosa | Perché |
|---|---|
| **Il dominio** `piedinauti.it`, con accesso al pannello DNS | per puntarlo al server |
| **Il VPS** con IP pubblico, almeno **2 GB di RAM** (1 GB + swap, vedi sotto) | per ospitare i 5 servizi |
| **Un servizio di posta (SMTP)** | senza, **non partono gli inviti** ai nuovi amministratori e responsabili |
| **Un secondo posto per i backup** (un altro server, il tuo computer) | un backup solo sul server che protegge non è un backup |

**La posta è l'unico requisito che non si improvvisa.** Servono: server SMTP, porta, utente e password.
Va bene la casella del tuo provider di dominio/hosting, oppure un servizio transazionale con piano gratuito
(Brevo, Mailgun, Amazon SES, Mailjet…). Per non finire nello spam il mittente deve essere del tuo dominio
(es. `noreply@piedinauti.it`) e il provider ti indicherà i record **SPF e DKIM** da aggiungere al DNS.
Spedire direttamente dal VPS, senza un provider, di solito finisce nello spam: sconsigliato.

Se questo VPS è lo stesso che usi per lo sviluppo, usa una cartella e un progetto Compose diversi
(`/srv/piedinauti`, non la cartella di sviluppo) e ferma lo sviluppo, per non far confliggere porte e volumi.

---

## 1. DNS: punta il dominio al server

Nel pannello del tuo registrar (dove hai comprato il dominio) crea il record:

| Tipo | Nome | Valore | TTL |
|---|---|---|---|
| **A** | `@` (o `piedinauti.it`) | **l'indirizzo IPv4 del VPS** | 300 |

- Se esistono già un record `A` che punta altrove (pagina "in costruzione" del registrar) o un record
  `AAAA`, **correggili o eliminali**: un `AAAA` sbagliato fa fallire l'emissione del certificato.
- Verifica (può servire da qualche minuto a qualche ora):

```bash
$ dig +short piedinauti.it        # deve restituire l'IP del VPS
```

> Il sito risponde solo su `piedinauti.it`. Per servire anche `www.piedinauti.it` servirebbero un record DNS
> in più e un blocco in `docker/caddy/Caddyfile` che lo reindirizzi al dominio principale.

---

## 2. Prepara il server (una volta sola)

Collegati: `ssh root@IP-DEL-VPS`.

```bash
# Aggiornamenti e strumenti
apt update && apt upgrade -y
apt install -y ufw curl ca-certificates dnsutils unattended-upgrades fail2ban
timedatectl set-timezone Europe/Rome

# Un utente per gestire il sito (non usare root tutti i giorni)
adduser --disabled-password --gecos "" deploy
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/       # la tua chiave SSH, se ti colleghi con la chiave
chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh && chmod 600 /home/deploy/.ssh/authorized_keys

# Docker (script ufficiale) e permesso a "deploy"
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy

# Cartella del sito
mkdir -p /srv/piedinauti && chown deploy:deploy /srv/piedinauti

# Firewall: solo SSH e web
ufw default deny incoming && ufw default allow outgoing
ufw allow 22/tcp           # SSH (se usi un'altra porta per ssh, mettila qui)
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp          # HTTP/3
ufw --force enable
```

**Prima di chiudere questa sessione**, apri un'altra finestra e verifica di poter entrare come `deploy`
(`ssh deploy@IP-DEL-VPS`) e che `docker ps` funzioni. Solo dopo, per sicurezza:

```bash
# sshd: niente password, niente root (solo se l'accesso con chiave come deploy funziona!)
sed -i 's/^#\?PasswordAuthentication .*/PasswordAuthentication no/; s/^#\?PermitRootLogin .*/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl reload ssh
```

**Server con 1 GB di RAM?** Aggiungi swap: `fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile && echo '/swapfile none swap sw 0 0' >> /etc/fstab`.

> **Docker e firewall:** Docker pubblica le porte aggirando `ufw`. Per questo la configurazione di produzione
> pubblica **solo 80 e 443** e il database non è raggiungibile da fuori. Non aggiungere mai `ports:` al
> servizio `db` in `docker-compose.prod.yml`.

---

## 3. Collega il server a GitHub

Il flusso, in breve:

```
tuo computer ── git push ──▶ GitHub ── Actions costruisce ──▶ ghcr.io (immagini piedinauti-app e -web)
                               │                                   │
                               └──── git pull (codice, script) ──▶ SERVER ◀── docker pull ──┘
```

Sul server servono quindi **due accessi** a GitHub, una volta sola.

### 3a. Le immagini: prima di tutto fai girare Actions

Il workflow `.github/workflows/immagini.yml` parte a ogni `git push` su `main` (o `master`) e pubblica
`ghcr.io/<proprietario>/piedinauti-app` e `…/piedinauti-web`, con l'etichetta `sha-` + i primi 7 caratteri del
commit. Fai il push e guarda la scheda **Actions** del repository: il primo giro dura 5-10 minuti.
Se fallisce con un errore di permessi sul pacchetto: *Settings → Actions → General → Workflow permissions →
Read and write permissions*.

### 3b. Accesso alle immagini (`docker login`)

I pacchetti nascono **privati** (come il repository). Sul server, come utente `deploy`:

1. su GitHub: *Settings (del tuo profilo) → Developer settings → Personal access tokens → Tokens (classic)
   → Generate new token*, con **solo** il permesso `read:packages` (se vuoi, scadenza lunga; annotatela);
2. sul server:

```bash
$ echo "IL_TOKEN" | docker login ghcr.io -u TUO_UTENTE_GITHUB --password-stdin
```

Le credenziali restano in `~/.docker/config.json`. (Alternativa: rendere pubblici i due pacchetti da
*GitHub → profilo/organizzazione → Packages → piedinauti-app → Package settings → Change visibility*: allora
non serve alcun login. Nelle immagini ci sono il codice e le risorse, **mai** le password: stanno solo nei
file `.env` del server.)

### 3c. Accesso al codice (`git clone`)

Se il repository è **pubblico**, basta `git clone https://github.com/…`. Se è **privato**, usa una *deploy key*
di sola lettura:

```bash
$ ssh-keygen -t ed25519 -N "" -f ~/.ssh/piedinauti_deploy -C "server piedinauti"
$ cat ~/.ssh/piedinauti_deploy.pub          # copia l'intera riga
```

Su GitHub: *repository → Settings → Deploy keys → Add deploy key*, incolla la riga, **lascia disattivato**
«Allow write access». Poi sul server:

```bash
$ cat >> ~/.ssh/config <<'FINE'
Host github-piedinauti
    HostName github.com
    User git
    IdentityFile ~/.ssh/piedinauti_deploy
    IdentitiesOnly yes
FINE
$ chmod 600 ~/.ssh/config
$ git clone git@github-piedinauti:PROPRIETARIO/REPOSITORY.git /srv/piedinauti
```

(alla prima connessione conferma l'impronta di github.com con `yes`).

---

## 4. Configurazione (sul server, una volta sola)

```bash
$ cd /srv/piedinauti
$ ./scripts/crea-env-produzione.sh piedinauti.it
```

(Ricava da solo il proprietario GitHub dall'indirizzo del repository clonato; se non ci riesce, aggiungilo:
`./scripts/crea-env-produzione.sh piedinauti.it tuo-utente-github`.)

Crea due file leggibili solo da te, con **password del database e chiave dell'applicazione casuali**:
`.env` (per Docker Compose: dominio, password del database, `GITHUB_PROPRIETARIO`, `IMMAGINE_TAG`) e `src/.env`
(per Laravel). Sono nel `.gitignore`: **non finiscono mai su GitHub**.

Poi apri `src/.env` e sostituisci i valori della posta con quelli del tuo provider:

```ini
MAIL_HOST=smtp.il-tuo-provider.it
MAIL_PORT=587
MAIL_USERNAME=utente-smtp
MAIL_PASSWORD=password-smtp
MAIL_FROM_ADDRESS="noreply@piedinauti.it"
```

(porta `587` = STARTTLS, va bene quasi sempre; se il provider indica `465` aggiungi `MAIL_SCHEME=smtps`).

**Conserva subito una copia di `.env` e `src/.env` in un posto sicuro** (gestore di password, cassaforte
cifrata): contengono le credenziali e non sono nei backup del database.

Poi controlla tutto:

```bash
$ ./scripts/controlla-produzione.sh
```

Segnala, una riga per controllo: file e permessi, `APP_DEBUG=false`, password robuste, posta ancora da
configurare, dominio che punta davvero a questo server, porte 80/443 libere, Docker e Compose, immagini raggiungibili. **Non
procedere finché non ci sono errori** (gli avvisi vanno letti ma non bloccano).

---

## 5. Primo avvio

```bash
$ ./scripts/aggiorna.sh
```

Fa `git pull`, controlla che le immagini di quel commit ci siano su ghcr.io, le scarica, avvia tutto e aspetta
che `app` sia sana (fino a un paio di minuti). Per seguire con calma, in un altro terminale:

```bash
$ docker compose ps                  # "app" deve diventare "healthy"
$ docker compose logs -f web app     # Ctrl+C per uscire
```

Cosa succede da solo: nasce il database con i **due ruoli** (amministratore e applicazione, docs/RLS.md),
partono le **migrazioni** (che attivano anche la sicurezza a livello di riga), Caddy ottiene il **certificato
HTTPS** per `piedinauti.it`.

Apri **https://piedinauti.it**: deve comparire la pagina di accesso con il lucchetto.
Se il certificato non arriva, vedi *Se qualcosa non va*.

### Il primo amministratore globale

Non serve la posta: la password si sceglie da riga di comando.

```bash
$ docker compose exec app php artisan piedinauti:admin-globale tua@email.it Nome Cognome
# chiede la password (minimo 8 caratteri)
```

Accedi, e prova la posta **prima di invitare qualcuno**:

```bash
$ docker compose exec app php artisan piedinauti:prova-posta tua@email.it
```

Invia subito un'email di prova (senza passare dalla coda) e, se qualcosa è sbagliato, mostra l'errore. Se
l'email arriva (controlla anche lo spam), la posta funziona. Se non arriva o c'è un errore: controlla
`MAIL_*` in `src/.env`, poi `docker compose restart app queue` e riprova.

Poi, dal sito: **Città → Nuova città**, e **Amministratori → Invita un amministratore**.

---

## 6. Backup (non saltare questo passo)

I backup contengono dati personali di minori: restano sul server con permessi `700/600`.

```bash
$ make backup                  # un backup subito
$ make verifica-backup         # PROVA che si ripristina (usa un database temporaneo: quello vero non si tocca)
```

**Ogni notte, in automatico** (`crontab -e` come utente `deploy`):

```cron
30 3 * * * cd /srv/piedinauti && BACKUP_REMOTE="utente@altro-server:/backups/piedinauti/" ./scripts/backup.sh >> backups/backup.log 2>&1
```

- Tiene gli ultimi **14 giorni** (`BACKUP_GIORNI` per cambiare).
- `BACKUP_REMOTE` copia ogni backup **su un'altra macchina** (richiede accesso ssh con chiave, senza
  password). Senza, il backup resta solo sul server: se il server si rompe, si perde tutto. Alternativa:
  scaricare ogni tanto la cartella `backups/` con `rsync`/`scp` sul tuo computer.
- **Verifica ogni mese** con `make verifica-backup`: un backup mai ripristinato è solo una speranza.

**Ripristinare** (sostituisce il database; prima fa una copia dello stato attuale):

```bash
$ make ripristina FILE=backups/piedinauti-20261006-033000.dump
```

---

## 7. Aggiornare il sito (le volte successive)

1. **Sul tuo computer:** `git push` (su `main`).
2. **Su GitHub:** aspetta che l'azione *Actions → Immagini* diventi verde (5-10 minuti; dalla seconda volta
   la cache la rende più veloce).
3. **Sul server:**

```bash
$ cd /srv/piedinauti && ./scripts/aggiorna.sh
```

In ordine: `git pull`, controllo che le immagini di quel commit esistano (se Actions non ha finito si ferma
e te lo dice, senza toccare nulla), **backup** (se non riesce, non aggiorna), download, avvio della nuova
versione, attesa che sia sana. Le migrazioni del database partono da sole. Alla fine controlla
`https://piedinauti.it/up`.

`git pull` da solo **non** cambia la versione in funzione: la sceglie `IMMAGINE_TAG` in `.env`, che
imposta solo `aggiorna.sh`. Sul server non si modifica mai il codice: se `git pull` si lamenta di modifiche
locali, guarda `git status`.

**Tornare indietro** (sul server): `./scripts/rollback.sh` rimette la versione precedente (se serve la
riscarica da ghcr.io). Cambia solo il codice: il database resta com'è dopo le migrazioni (va bene quando le
migrazioni hanno solo aggiunto qualcosa, che è il caso normale). Se serve tornare anche ai dati di prima
dell'aggiornamento: `./scripts/ripristina.sh backups/<backup-fatto-prima>.dump`. Rilanciato una seconda volta,
`rollback.sh` torna avanti. Per ripartire dopo un rollback: correggi il codice, `git push`, e `aggiorna.sh`.

## 8. Tenere d'occhio il sito

```bash
$ docker compose ps                         # stato dei servizi
$ docker compose logs --tail 100 app web    # errori recenti
$ df -h /                                   # spazio su disco
$ docker system df                          # spazio usato da Docker
```

- **Un controllo esterno** (gratuito, per esempio UptimeRobot) su `https://piedinauti.it/up`: ti avvisa se
  il sito non risponde.
- I log dei container ruotano da soli (5 file da 10 MB per servizio).
- Gli aggiornamenti di sicurezza del sistema sono automatici (`unattended-upgrades`); ogni tanto
  `apt update && apt upgrade` e, se richiesto, riavvia il server (`reboot`): i servizi ripartono da soli.
- **Rinnovo del certificato:** automatico, nessuna azione.

---

## 9. Dati personali e privacy

Il sito tratta **dati di minori** (nomi dei bambini, presenze, fermate). In breve, perché sia in regola
(non è una consulenza legale, parlane con chi segue la privacy dell'ente/associazione):

- serve un'**informativa** per i genitori e un **titolare del trattamento** definito;
- chi fornisce il VPS e il servizio di posta sono **responsabili del trattamento**: servono i relativi accordi;
- decidi per quanto **conservare** le presenze e i backup (i backup più vecchi di 14 giorni si cancellano da soli);
- l'accesso è già limitato per ruolo e per città, ma le **credenziali** vanno tenute con cura: chi ha accesso
  al server o ai file `.env` ha accesso a tutto.

---

## Se qualcosa non va

| Sintomo | Cosa controllare |
|---|---|
| Il browser dice "connessione non sicura" / niente lucchetto | `docker compose logs web`: di solito il **DNS non punta al server** o le **porte 80/443 sono chiuse**. Verifica con `dig +short piedinauti.it` e con `./scripts/controlla-produzione.sh`. Dopo aver corretto, `docker compose restart web`. |
| **502 Bad Gateway** | `docker compose ps`: `app` è in avvio o in errore? `docker compose logs --tail 100 app` (spesso una migrazione fallita o una variabile mancante in `src/.env`). |
| `app` resta "unhealthy" | `docker compose logs app`. Se parla di database: le password in `.env` e `src/.env` devono coincidere (`controlla-produzione.sh`). |
| `aggiorna.sh`: «le immagini … non sono (ancora) su ghcr.io» | Actions non ha finito (o è fallita: guarda la scheda Actions), oppure manca il `docker login ghcr.io` (passo 3b), oppure `GITHUB_PROPRIETARIO` in `.env` non coincide col proprietario del repository (minuscolo). |
| `git pull`: «Permission denied (publickey)» | la deploy key (passo 3c): `ssh -T git@github-piedinauti` deve rispondere col tuo nome; il remote deve usare `github-piedinauti`: `git remote -v`. |
| Gli inviti non arrivano | `php artisan piedinauti:prova-posta` (passo 5); `docker compose logs --tail 50 queue`; controlla SPF/DKIM del dominio e la cartella spam. |
| Errore `row-level security policy` nei log | il codice ha provato a scrivere dati in una città diversa da quella della richiesta: è un difetto da correggere (docs/RLS.md). |
| Il sito è lento al primo avvio | normale: il primo avvio fa migrazioni e riscalda la cache. |
| Spazio su disco quasi pieno | `docker system df`; `docker image prune -a` (toglie le immagini non usate, **non** i dati; poi per un rollback verranno riscaricate); sposta i vecchi backup altrove. |
| Voglio cambiare la password del database | non basta cambiarla nei file (è già nel volume del database). Apri `make psql`, scrivi `ALTER ROLE piedinauti_app PASSWORD 'nuova-password';`, poi aggiorna `DB_PASSWORD` **sia in `.env` sia in `src/.env`** e `docker compose up -d --force-recreate app queue scheduler`. |

---

## Riepilogo dei comandi

| Cosa | Comando |
|---|---|
| Rilasciare | `git push`, attendi Actions, poi sul server `./scripts/aggiorna.sh` |
| Tornare alla versione precedente | `./scripts/rollback.sh` *(sul server)* |
| Controllare la configurazione | `./scripts/controlla-produzione.sh` |
| Backup / verifica / ripristino | `make backup` / `make verifica-backup` / `make ripristina FILE=…` |
| Stato / log | `docker compose ps` / `docker compose logs -f app web` |
| Creare un amministratore globale | `docker compose exec app php artisan piedinauti:admin-globale email Nome Cognome` |
| Riavviare tutto | `docker compose restart` |
