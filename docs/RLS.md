# Sicurezza a livello di riga (RLS)

L'isolamento tra città ha **tre barriere**, una dentro l'altra:

1. **Il codice**: ogni modello con una città ha un filtro automatico (`citta_id = la tua città`).
2. **I vincoli del database**: le chiavi esterne composte rifiutano legami tra record di città diverse.
3. **La RLS di PostgreSQL** (questo documento): il database stesso non mostra e non modifica righe di
   un'altra città, qualunque query riceva.

La terza barriera serve quando le prime due falliscono per un errore di codice: una query scritta a mano,
un `withoutGlobalScopes()` dimenticato, un `where` mancante. In quel caso il database risponde comunque
solo con i dati della città della richiesta.

## Come funziona

Per ogni richiesta web l'applicazione comunica al database, su quella connessione, due impostazioni:

| Impostazione | Valori | Significato |
|---|---|---|
| `app.limitato` | `on` / `off` | la richiesta è limitata a una città? |
| `app.citta_id` | un id, o vuoto | quale città (vuoto = nessuna) |

Chi lo imposta è `App\Support\CittaCorrente` (lo stesso oggetto che governa i filtri dei modelli, quindi le
due cose restano sempre d'accordo). Le regole del database leggono queste impostazioni:

- **non limitato** (amministratore globale, comandi da console, code, ospiti come la pagina di accesso):
  si vede tutto, come prima;
- **limitato a una città**: si vedono e si scrivono solo le righe di quella città;
- **limitato a nessuna città** (utente senza città): non si vede nulla.

Le regole sono su tutte le tabelle con un `citta_id`, sulla tabella `citta` e su `utente_ruolo` (che segue
la persona a cui appartiene). Sono `FORCE`: valgono anche per il proprietario delle tabelle, che è il ruolo
con cui l'applicazione si collega.

## I due ruoli del database

| Ruolo | Variabile in `.env` | Cosa fa |
|---|---|---|
| **Amministratore** (superutente) | `DB_ADMIN_USER`, `DB_ADMIN_PASSWORD` | backup, ripristini, manutenzione. **Laravel non lo usa mai.** |
| **Applicazione** | `DB_USERNAME`, `DB_PASSWORD` (anche in `src/.env`) | possiede database e tabelle; è quello che usa Laravel. Non può saltare la RLS. |

Un superutente salta sempre la RLS: per questo l'applicazione non deve collegarsi con quello. È anche per
questo che i backup fatti dall'amministratore sono completi, con i dati di tutte le città.

- **Nuova installazione**: `docker/db/init/01-ruolo-applicazione.sh` crea il ruolo dell'applicazione la
  prima volta che nasce il volume del database. Non serve fare nulla.
- **Installazione esistente**: una volta sola, `./scripts/passa-a-rls.sh`. Fa prima un backup, passa la
  proprietà al nuovo ruolo, aggiorna i file `.env` (tenendo copia degli originali) e ricrea il database dei
  test. Poi: `make up && make artisan ARGS="migrate" && make test`.

Se i test segnalano *"Il database è collegato con un ruolo che salta la RLS"*, il ruolo in `DB_USERNAME` è
ancora un superutente: serve la conversione.

## Per chi sviluppa

- **Una nuova tabella con `citta_id` va protetta.** Il test `RlsTest::test_ogni_tabella_con_una_citta_e_protetta_e_ha_una_regola`
  fallisce finché la tabella non è nella lista `CON_CITTA` di una migrazione di RLS (copiare lo schema di
  `enable_row_level_security`: `ENABLE`, `FORCE`, `CREATE POLICY isolamento_citta ... rls_consente(citta_id)`).
- **Controlli di unicità sull'email**: la regola `unique` di Laravel interroga il database e, per
  un amministratore di città, non vede le persone delle altre città. Si usa `App\Rules\EmailNonUsata`.
  Vale per qualsiasi controllo che deve guardare oltre la propria città (per esempio un `exists` o un
  `unique` su una tabella con RLS): va fatto dentro `CittaCorrente::senzaLimiti()`.
- **Un errore `new row violates row-level security policy`** significa che il codice ha provato a scrivere
  in una città diversa da quella della richiesta: è un bug da correggere, non un problema del database.
- Le migrazioni girano come proprietario e **senza limite**: possono leggere e scrivere tutto.

## Cosa la RLS non fa

- **Non protegge se la richiesta non è limitata.** Il database imita lo stato di `CittaCorrente`: se un
  percorso web non passasse da `ImpostaCittaCorrente`, sarebbe "non limitato" anche per il database.
  Per questo il middleware è nel gruppo `web` e c'è un test che lo verifica.
- **Non difende da un'iniezione SQL** che esegua istruzioni arbitrarie: chi può eseguire SQL qualsiasi
  può anche cambiare `app.limitato`. La RLS riduce il danno degli errori di logica, non sostituisce
  l'uso di query parametrizzate.
- **Non nasconde i dati all'amministratore del database**, per scelta: serve per i backup.

## Verifiche già fatte

La migrazione e lo script di conversione sono stati provati su PostgreSQL con lo schema reale: letture e
scritture per ogni stato (limitato, non limitato, nessuna città), cancellazioni a cascata dentro la propria
città, ripristino delle impostazioni dopo un `ROLLBACK` o un errore dentro una transazione, migrazione
ripetibile, backup completo con l'amministratore.
