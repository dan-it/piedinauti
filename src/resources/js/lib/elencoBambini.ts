// The list of the city's children (names only), kept on the phone so that a child can be found and
// added to a stop for the day even without signal.

import { chiamaJson } from '@/lib/chiamate';
import { leggiDato, salvaDato } from '@/lib/coda';

export interface BambinoElenco {
    id: number;
    nome: string;
}

interface ElencoSalvato {
    aggiornato: number;
    elenco: BambinoElenco[];
}

const CHIAVE = 'bambini';
const VALIDITA_MS = 6 * 60 * 60 * 1000;

// Downloads the list when the saved one is missing or older than a few hours.
export async function aggiornaElencoBambini(url: string, forza = false): Promise<void> {
    const salvato = await leggiDato<ElencoSalvato>(CHIAVE).catch(() => undefined);

    if (!forza && salvato && Date.now() - salvato.aggiornato < VALIDITA_MS) {
        return;
    }

    const risposta = await chiamaJson<{ bambini: BambinoElenco[] }>(url);

    if (risposta.ok) {
        await salvaDato(CHIAVE, { aggiornato: Date.now(), elenco: risposta.dati.bambini } satisfies ElencoSalvato);
    }
}

// Lower case, no accents: "Zoë" matches "zoe".
export function normalizza(testo: string): string {
    return testo.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

// Searches the saved list. Every word typed must appear in the name, in any order
// ("rossi luca" finds "Luca Rossi").
export function filtra(elenco: BambinoElenco[], testo: string, esclusi: Set<number>, limite = 10): BambinoElenco[] {
    const parole = normalizza(testo.trim()).split(/\s+/).filter(Boolean);

    if (parole.join('').length < 2) {
        return [];
    }

    return elenco.filter((bambino) => !esclusi.has(bambino.id) && parole.every((parola) => normalizza(bambino.nome).includes(parola))).slice(0, limite);
}

export async function cercaLocale(testo: string, esclusi: Set<number>, limite = 10): Promise<BambinoElenco[]> {
    const salvato = await leggiDato<ElencoSalvato>(CHIAVE).catch(() => undefined);

    return salvato ? filtra(salvato.elenco, testo, esclusi, limite) : [];
}
