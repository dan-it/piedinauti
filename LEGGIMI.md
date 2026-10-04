# Fase 1 (seconda parte) – inviti

## Applicazione

Dalla cartella del progetto (quella che contiene `src/`):

```bash
unzip -o piedinauti-inviti.zip
make test
```

Non servono migrazioni né nuove dipendenze.

## Provare l'invito

```bash
make artisan ARGS="piedinauti:invita anna@example.com Anna Neri --citta='Città di prova' --ruolo=responsabile --ruolo=accompagnatore"
```

L'email arriva su Mailpit (http://localhost:8026): il pulsante "Imposta la password" apre la pagina
per scegliere la password. Per rimandare l'invito: `make artisan ARGS="piedinauti:reinvia anna@example.com"`.
Il primo amministratore di una città si invita con `--ruolo=admin_citta`; un amministratore globale
con `--ruolo=admin_globale` e senza `--citta`.

## Come funziona

- La persona viene creata senza password, con i ruoli indicati, e riceve un'email.
- Il link vale 7 giorni (broker `inviti` in `config/auth.php`); il reset password normale resta di 60 minuti.
- Seguire il link verifica anche l'indirizzo email.
- Un nuovo invito annulla il link precedente.
- Le schermate di gestione che useranno questa logica (`App\Actions\InvitaPersona`) arrivano con la Fase 2.
