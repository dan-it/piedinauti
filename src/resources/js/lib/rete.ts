// The connection state of the chaperone's screen, on top of the outbox in coda.ts.
//
// Every tap goes into the outbox first (so it survives a reload or a dead battery) and is sent
// straight away if there is signal. When sending fails for lack of signal, the screen keeps
// working and the taps are sent as soon as the phone is back online.

import { chiamaJson } from '@/lib/chiamate';
import * as coda from '@/lib/coda';
import { reactive } from 'vue';

export const rete = reactive({
    // Taps kept on the phone and not yet confirmed by the server.
    inAttesa: 0,
    inInvio: false,
    offline: typeof navigator !== 'undefined' ? !navigator.onLine : false,
    // The server asked to sign in again: the waiting taps are kept until the person does.
    sessioneScaduta: false,
});

type Ascoltatore = (esito: coda.Esito) => void;
const ascoltatori = new Set<Ascoltatore>();

// Be told when an action was confirmed or refused by the server.
export function sulEsito(ascoltatore: Ascoltatore): () => void {
    ascoltatori.add(ascoltatore);

    return () => {
        ascoltatori.delete(ascoltatore);
    };
}

const avvisa = (esito: coda.Esito) => ascoltatori.forEach((ascoltatore) => ascoltatore(esito));

export async function aggiornaConteggio(): Promise<void> {
    try {
        rete.inAttesa = await coda.contaAzioni();
    } catch {
        rete.inAttesa = 0;
    }
}

const invia: coda.Invia = (azione) => chiamaJson(azione.url, { metodo: 'POST', corpo: azione.corpo });

let ripassare = false;

// Sends what is waiting. Safe to call at any time and as often as wanted.
export async function provaInvio(): Promise<void> {
    if (rete.inInvio) {
        // A tap arrived while sending: go round once more when this run ends.
        ripassare = true;

        return;
    }

    rete.inInvio = true;
    let interrotto: coda.Interruzione | null = null;

    try {
        const risultato = await coda.inviaCoda(invia, avvisa);
        interrotto = risultato.interrotto;
        rete.sessioneScaduta = interrotto === 'sessione';
        rete.offline = interrotto === 'rete' ? true : typeof navigator !== 'undefined' && !navigator.onLine;
    } catch {
        // The device storage is not available: nothing to send from here.
    } finally {
        rete.inInvio = false;
        await aggiornaConteggio();
    }

    if (ripassare || (rete.inAttesa > 0 && interrotto === null)) {
        ripassare = false;
        void provaInvio();
    }
}

// Puts an action in the outbox and tries to send it. If the phone cannot even store it, it is
// sent directly and a failure is reported at once, so a tap is never silently lost.
export async function accoda(azione: coda.Azione): Promise<void> {
    try {
        await coda.aggiungiAzione(azione);
    } catch {
        try {
            const risposta = await invia(azione);
            avvisa(
                risposta.ok
                    ? { tipo: 'inviata', azione, risposta }
                    : { tipo: 'rifiutata', azione, stato: risposta.stato, messaggio: risposta.dati.message ?? 'Non è stato possibile salvare.' },
            );
        } catch {
            avvisa({ tipo: 'rifiutata', azione, stato: 0, messaggio: 'Nessuna connessione e il telefono non può tenere la presenza in memoria: riprova.' });
        }

        return;
    }

    await aggiornaConteggio();
    void provaInvio();
}

let avviata = false;

// Called once when the app starts: sends whatever is waiting now and whenever signal may be back.
export function avviaSincronizzazione(): void {
    if (avviata || typeof window === 'undefined') {
        return;
    }
    avviata = true;

    window.addEventListener('online', () => {
        rete.offline = false;
        void provaInvio();
    });
    window.addEventListener('offline', () => {
        rete.offline = true;
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void provaInvio();
        }
    });

    // Signal can come back without the browser noticing: retry while something is waiting.
    window.setInterval(() => {
        if (rete.inAttesa > 0) {
            void provaInvio();
        }
    }, 20000);

    void aggiornaConteggio().then(() => {
        if (rete.inAttesa > 0) {
            void provaInvio();
        }
    });
}

// Forgets everything kept on the phone (waiting taps, children list, saved pages): used at sign out,
// so the next person on a shared phone sees nothing of the previous one.
export async function pulisciDatiLocali(): Promise<void> {
    try {
        await coda.svuotaTutto();
    } catch {
        // nothing stored
    }

    if ('caches' in window) {
        const nomi = await caches.keys();
        await Promise.all(nomi.filter((nome) => nome.startsWith('piedinauti-pagine-')).map((nome) => caches.delete(nome)));
    }

    rete.inAttesa = 0;
}
