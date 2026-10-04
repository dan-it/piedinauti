# Fase 1 – modello dati, ruoli e isolamento per città

## Applicazione

Dalla cartella del progetto (quella che contiene `src/`), con i container avviati:

```bash
unzip piedinauti-fase1.zip          # crea la cartella fase1/
./fase1/apply.sh .                  # copia i file in src/ e aggiorna il Makefile
make artisan ARGS="migrate"         # crea le tabelle
make test                           # esegue i test (crea il database piedinauti_test)
make artisan ARGS="piedinauti:admin-globale tua@email.it Nome Cognome"   # primo accesso
```

Per i dati di prova in locale: `make artisan ARGS="db:seed"` (account `admin@example.com`,
`admin-citta@example.com`, `responsabile@example.com`, `accompagnatore@example.com`, password `password`).

## Cosa contiene

- **Migrazioni** per le dieci tabelle. Le tabelle figlie ripetono `citta_id` con chiavi esterne
  composte: il database rifiuta qualsiasi legame tra record di città diverse.
- **Modelli** con isolamento per città (`CittaScope`, `CittaCorrente`, middleware `ImpostaCittaCorrente`).
- **Ruoli multipli** per persona (`Ruolo`, tabella `utente_ruolo`).
- **Presenze** idempotenti con `Presenza::registra()`.
- **Adattamenti dello starter kit**: nome e cognome al posto di `name`, registrazione pubblica
  rimossa, pagina iniziale sostituita da un reindirizzamento, login e profilo in italiano.
- **Test** su isolamento, vincoli del database e ruoli.

## Non ancora fatto

Inviti e reset password dalla schermata di amministrazione, Policy di autorizzazione e
Row-Level Security. Le altre pagine del kit (dashboard, password dimenticata, ecc.) sono
ancora in inglese.
