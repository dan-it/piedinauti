// Small helpers shared by the report pages.

export interface FiltriReport {
    da: string;
    a: string;
    linea_scelta: number | null;
    citta_scelta: number | null;
    citte: { id: number; nome: string }[];
}

// The filters as query parameters, leaving out what is not set (and the city for non-global users).
export function parametri(filtri: FiltriReport, extra: Record<string, string | number | null> = {}): Record<string, string | number> {
    const tutti: Record<string, string | number | null> = {
        da: filtri.da,
        a: filtri.a,
        linea: filtri.linea_scelta,
        citta: filtri.citte.length > 0 ? filtri.citta_scelta : null,
        ...extra,
    };

    return Object.fromEntries(Object.entries(tutti).filter(([, valore]) => valore !== null && valore !== '')) as Record<string, string | number>;
}

// "2026-10-05" -> "05/10/2026"
export function italiana(iso: string): string {
    const [anno, mese, giorno] = iso.split('-');

    return `${giorno}/${mese}/${anno}`;
}

// "2026-10-05" -> "lun 05/10"
export function giornoBreve(iso: string): string {
    const data = new Date(`${iso}T12:00:00`);
    const nome = data.toLocaleDateString('it-IT', { weekday: 'short' }).replace('.', '');

    return `${nome} ${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
}

export function percentuale(valore: number | null): string {
    return valore === null ? '—' : `${valore.toLocaleString('it-IT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
}

// Minutes between the expected arrival and the real one: positive = late.
export function scartoTesto(minuti: number | null): string {
    if (minuti === null) {
        return '—';
    }
    if (minuti === 0) {
        return 'in orario';
    }

    return minuti > 0 ? `${minuti} min in ritardo` : `${-minuti} min in anticipo`;
}

export function scartoMedioTesto(valore: number | null): string {
    if (valore === null) {
        return '—';
    }

    const arrotondato = Math.round(valore);

    return arrotondato === 0 ? 'in orario' : arrotondato > 0 ? `${arrotondato} min in ritardo` : `${-arrotondato} min in anticipo`;
}

// Today and the other quick ranges, in the phone's local time (YYYY-MM-DD).
const iso = (data: Date) => data.toLocaleDateString('sv-SE');

export function scorciatoie(): { etichetta: string; da: string; a: string }[] {
    const oggi = new Date();
    const giorniFa = (n: number) => {
        const d = new Date(oggi);
        d.setDate(d.getDate() - n);

        return d;
    };

    return [
        { etichetta: 'Ultimi 7 giorni', da: iso(giorniFa(6)), a: iso(oggi) },
        { etichetta: 'Ultimi 30 giorni', da: iso(giorniFa(29)), a: iso(oggi) },
        { etichetta: 'Questo mese', da: iso(new Date(oggi.getFullYear(), oggi.getMonth(), 1)), a: iso(oggi) },
        { etichetta: 'Mese scorso', da: iso(new Date(oggi.getFullYear(), oggi.getMonth() - 1, 1)), a: iso(new Date(oggi.getFullYear(), oggi.getMonth(), 0)) },
    ];
}
