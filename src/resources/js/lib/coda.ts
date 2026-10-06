// The phone's outbox: taps are kept on the device (IndexedDB) and sent to the server when there is
// signal. Nothing here depends on Vue, so it can be tested on its own.
//
// Rules:
// - A new action with the same "chiave" (same child on the same stop, or the line's arrival)
//   replaces the older one: only the last tap matters, and less is sent.
// - Actions are sent one at a time, oldest first. A refusal by the server (403, 404, 422...) removes
//   the action; a missing connection, an expired session or a server error stops the run and keeps it.
// - Sending is safe to repeat: the server stores one row per child, line and day, and keeps the
//   most recent mark, so a tap that was sent twice (or too late) cannot do harm.

export type TipoAzione = 'presenza' | 'arrivo';

export interface Azione {
    id: string;
    // Actions with the same key replace each other.
    chiave: string;
    tipo: TipoAzione;
    url: string;
    // JSON body, including "registrata_il": the moment of the tap, which the server uses.
    corpo: Record<string, unknown>;
    // Milliseconds since 1970: decides the sending order.
    creata: number;
    // What the screen needs to show the action again after a reload (child, stop, state...).
    riferimento: Record<string, unknown>;
}

export interface RispostaInvio {
    ok: boolean;
    stato: number;
    dati: { message?: string } & Record<string, unknown>;
}

export type Invia = (azione: Azione) => Promise<RispostaInvio>;

export type Interruzione = 'rete' | 'sessione' | 'server';

export type Esito =
    | { tipo: 'inviata'; azione: Azione; risposta: RispostaInvio }
    | { tipo: 'rifiutata'; azione: Azione; stato: number; messaggio: string };

export interface RisultatoInvio {
    inviate: number;
    rifiutate: number;
    // Why sending stopped before the end, if it did: the remaining actions are kept for next time.
    interrotto: Interruzione | null;
}

const NOME_DB = 'piedinauti';
const VERSIONE_DB = 1;
const AZIONI = 'azioni';
const DATI = 'dati';

function apri(): Promise<IDBDatabase> {
    return new Promise((risolvi, rifiuta) => {
        const richiesta = indexedDB.open(NOME_DB, VERSIONE_DB);

        richiesta.onupgradeneeded = () => {
            const db = richiesta.result;

            if (!db.objectStoreNames.contains(AZIONI)) {
                db.createObjectStore(AZIONI, { keyPath: 'id' }).createIndex('chiave', 'chiave');
            }
            if (!db.objectStoreNames.contains(DATI)) {
                db.createObjectStore(DATI);
            }
        };
        richiesta.onsuccess = () => risolvi(richiesta.result);
        richiesta.onerror = () => rifiuta(richiesta.error);
    });
}

// Runs one transaction and resolves with the value the callback returns, once it has completed.
async function transazione<T>(negozi: string[], modo: IDBTransactionMode, lavoro: (tx: IDBTransaction) => Promise<T> | T): Promise<T> {
    const db = await apri();

    return new Promise<T>((risolvi, rifiuta) => {
        const tx = db.transaction(negozi, modo);
        let risultato: T;

        tx.oncomplete = () => {
            db.close();
            risolvi(risultato);
        };
        tx.onerror = () => rifiuta(tx.error);
        tx.onabort = () => rifiuta(tx.error);

        Promise.resolve(lavoro(tx)).then(
            (valore) => {
                risultato = valore;
            },
            (errore) => {
                tx.abort();
                rifiuta(errore);
            },
        );
    });
}

const richiedi = <T>(richiesta: IDBRequest<T>): Promise<T> =>
    new Promise((risolvi, rifiuta) => {
        richiesta.onsuccess = () => risolvi(richiesta.result);
        richiesta.onerror = () => rifiuta(richiesta.error);
    });

// ----------------------------------------------------------------- the outbox

// Adds an action, replacing any older one with the same key.
export function aggiungiAzione(azione: Azione): Promise<void> {
    return transazione([AZIONI], 'readwrite', async (tx) => {
        const negozio = tx.objectStore(AZIONI);
        const vecchie = await richiedi(negozio.index('chiave').getAllKeys(azione.chiave));

        for (const id of vecchie) {
            negozio.delete(id);
        }
        negozio.put(azione);
    });
}

// All waiting actions, oldest first.
export function elencoAzioni(): Promise<Azione[]> {
    return transazione([AZIONI], 'readonly', async (tx) => {
        const tutte = await richiedi(tx.objectStore(AZIONI).getAll() as IDBRequest<Azione[]>);

        return tutte.sort((a, b) => a.creata - b.creata);
    });
}

export function rimuoviAzione(id: string): Promise<void> {
    return transazione([AZIONI], 'readwrite', (tx) => {
        tx.objectStore(AZIONI).delete(id);
    });
}

export function contaAzioni(): Promise<number> {
    return transazione([AZIONI], 'readonly', (tx) => richiedi(tx.objectStore(AZIONI).count()));
}

// ------------------------------------------------------------- small stored data

export function salvaDato(chiave: string, valore: unknown): Promise<void> {
    return transazione([DATI], 'readwrite', (tx) => {
        tx.objectStore(DATI).put(valore, chiave);
    });
}

export function leggiDato<T>(chiave: string): Promise<T | undefined> {
    return transazione([DATI], 'readonly', (tx) => richiedi(tx.objectStore(DATI).get(chiave) as IDBRequest<T | undefined>));
}

// Removes everything kept on the device (used when someone signs out).
export function svuotaTutto(): Promise<void> {
    return transazione([AZIONI, DATI], 'readwrite', (tx) => {
        tx.objectStore(AZIONI).clear();
        tx.objectStore(DATI).clear();
    });
}

// ------------------------------------------------------------------- sending

// What an answer from the server means for an action.
export function classifica(stato: number): 'ok' | 'rifiuto' | Interruzione {
    if (stato >= 200 && stato < 300) {
        return 'ok';
    }
    // Not signed in (any more) or the session's security token has expired: sign in again, then retry.
    if (stato === 401 || stato === 419) {
        return 'sessione';
    }
    if (stato === 0 || stato === 408 || stato === 429 || stato >= 500) {
        return 'server';
    }

    // The server understood and said no (not allowed, window closed, not found...): retrying won't help.
    return 'rifiuto';
}

let inCorso: Promise<RisultatoInvio> | null = null;

// Sends the waiting actions. Only one run at a time: a second call gets the same result.
export function inviaCoda(invia: Invia, aEsito?: (esito: Esito) => void): Promise<RisultatoInvio> {
    if (inCorso) {
        return inCorso;
    }

    inCorso = elabora(invia, aEsito).finally(() => {
        inCorso = null;
    });

    return inCorso;
}

async function elabora(invia: Invia, aEsito?: (esito: Esito) => void): Promise<RisultatoInvio> {
    let inviate = 0;
    let rifiutate = 0;

    for (const azione of await elencoAzioni()) {
        let risposta: RispostaInvio;

        try {
            risposta = await invia(azione);
        } catch {
            // No connection at all.
            return { inviate, rifiutate, interrotto: 'rete' };
        }

        const verdetto = classifica(risposta.stato);

        if (verdetto === 'ok') {
            await rimuoviAzione(azione.id);
            inviate++;
            aEsito?.({ tipo: 'inviata', azione, risposta });
        } else if (verdetto === 'rifiuto') {
            await rimuoviAzione(azione.id);
            rifiutate++;
            aEsito?.({ tipo: 'rifiutata', azione, stato: risposta.stato, messaggio: risposta.dati.message ?? 'Il server ha rifiutato questa azione.' });
        } else {
            return { inviate, rifiutate, interrotto: verdetto };
        }
    }

    return { inviate, rifiutate, interrotto: null };
}

// Builds an action, stamped with the moment of the tap.
export function nuovaAzione(dati: Omit<Azione, 'id' | 'creata'>): Azione {
    return { ...dati, id: crypto.randomUUID(), creata: Date.now() };
}
