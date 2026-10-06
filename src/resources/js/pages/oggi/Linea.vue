<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import SchedaDestinazioneOggi, { type ArrivoRegistrato } from '@/components/SchedaDestinazioneOggi.vue';
import SchedaFermataOggi, { type FermataOggi } from '@/components/SchedaFermataOggi.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { elencoAzioni } from '@/lib/coda';
import { aggiornaElencoBambini } from '@/lib/elencoBambini';
import { aggiornaConteggio, provaInvio, rete, sulEsito } from '@/lib/rete';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// "precedente": a stop just before the chaperone's own that the line lets them work on, like their own.
type FermataConDestinazione = FermataOggi & { destinazione: boolean; precedente: boolean };

const props = defineProps<{
    linea: { id: number; nome: string };
    data: string;
    data_iso: string;
    fermate: FermataConDestinazione[];
    arrivo: string | null;
    arrivo_registrato: ArrivoRegistrato | null;
    modifica_aperta: boolean;
    minuti_modifica: number;
    precedenti_visibili: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Oggi', href: '/oggi' },
    { title: props.linea.nome, href: '#' },
];

const copia = <T,>(valore: T): T => JSON.parse(JSON.stringify(valore));

// A local copy: each tap changes it at once. Taps still waiting to be sent (kept on the phone)
// are laid over the data the server gave, so nothing disappears after a reload.
const fermate = ref<FermataConDestinazione[]>(copia(props.fermate));
const arrivoRegistrato = ref<ArrivoRegistrato | null>(props.arrivo_registrato);

const applica = async () => {
    fermate.value = copia(props.fermate);
    arrivoRegistrato.value = props.arrivo_registrato;

    let azioni = [] as Awaited<ReturnType<typeof elencoAzioni>>;
    try {
        azioni = await elencoAzioni();
    } catch {
        return;
    }

    for (const azione of azioni) {
        const r = azione.riferimento as { giorno?: string; fermataId?: number; bambinoId?: number; presente?: boolean; nome?: string; temporaneo?: boolean; ora?: string; annullato?: boolean };

        // Only taps made for the day this screen shows.
        if (r.giorno !== props.data_iso) {
            continue;
        }

        if (azione.tipo === 'presenza') {
            const fermata = fermate.value.find((candidata) => candidata.id === r.fermataId);
            if (!fermata) {
                continue;
            }

            let bambino = fermata.bambini.find((candidato) => candidato.id === r.bambinoId);
            if (!bambino && r.nome && r.bambinoId !== undefined) {
                bambino = { id: r.bambinoId, nome: r.nome, presente: r.presente ?? null, temporaneo: !!r.temporaneo, altrove: null, modificabile: true };
                fermata.bambini.push(bambino);
            }
            if (bambino) {
                bambino.presente = r.presente ?? null;
            }
        } else if (azione.tipo === 'arrivo') {
            // The action still waiting is the latest intention: an arrival, or its cancellation.
            arrivoRegistrato.value = r.annullato ? null : r.ora ? { ora: r.ora, da: null } : arrivoRegistrato.value;
        }
    }
};

watch(() => [props.fermate, props.arrivo_registrato], applica);

// Fetches the server's current data again (keeping what is still waiting to be sent).
const ricarica = () => {
    // A reload keeps the scroll position and the page state by default.
    router.reload({ only: ['fermate', 'arrivo_registrato', 'arrivo', 'modifica_aperta'] });
};

// A message from the server about something sent late: refused, or already corrected by an administrator.
const messaggio = ref<string | null>(null);
let smetti: (() => void) | undefined;

// The clock, to close the window on the screen even while offline.
const adesso = ref(Date.now());
let orologio: ReturnType<typeof setInterval> | undefined;
let aggiornamento: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    void applica();
    void aggiornaConteggio();
    void provaInvio();

    if (navigator.onLine) {
        // The children's names, so a child can be found offline too.
        aggiornaElencoBambini(route('oggi.bambini')).catch(() => undefined);
    }

    orologio = setInterval(() => {
        adesso.value = Date.now();
    }, 15000);

    // The stops before the chaperone's own are also marked by other chaperones: refresh them while
    // the screen is open, but never while this phone's own taps are on their way (it could show a
    // state from before they arrived).
    aggiornamento = setInterval(() => {
        const haPrecedenti = fermate.value.some((fermata) => fermata.precedente);

        if (haPrecedenti && !document.hidden && navigator.onLine && !rete.inInvio && rete.inAttesa === 0) {
            ricarica();
        }
    }, 30000);

    smetti = sulEsito((esito) => {
        if (esito.tipo === 'inviata') {
            const dati = esito.risposta.dati;

            if (esito.azione.tipo === 'arrivo') {
                // Show what the server kept: the earliest tap wins, and a cancellation older than a
                // newer arrival leaves that arrival in place.
                arrivoRegistrato.value = dati.ora ? { ora: String(dati.ora), da: (dati.da as string | null) ?? null } : null;
            } else if (dati.applicata === false) {
                messaggio.value = 'Un amministratore aveva già corretto una presenza: la schermata è stata aggiornata.';
                ricarica();
            }

            return;
        }

        messaggio.value = esito.messaggio;
        ricarica();
    });
});

onBeforeUnmount(() => {
    clearInterval(orologio);
    clearInterval(aggiornamento);
    smetti?.();
});

// A page kept on the phone from another day must not be used: its list and states are not today's.
const oggiLocale = () => new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD in local time
const paginaVecchia = computed(() => {
    void adesso.value;

    return props.data_iso !== oggiLocale();
});

// When the phone is back online, a stale page refreshes itself.
watch(
    () => [rete.offline, paginaVecchia.value],
    () => {
        if (!rete.offline && paginaVecchia.value) {
            router.reload();
        }
    },
);

// The window closes 30 minutes after the line's arrival, by the phone's clock.
const chiusuraMs = computed(() => (props.arrivo ? new Date(`${props.data_iso}T${props.arrivo}:00`).getTime() + props.minuti_modifica * 60000 : null));
const modificaAperta = computed(() => props.modifica_aperta && !paginaVecchia.value && (chiusuraMs.value === null || adesso.value <= chiusuraMs.value));

// One indicator for everything: is what I tapped safe?
const stato = computed(() => {
    if (messaggio.value) {
        return { testo: messaggio.value, classe: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200', chiudibile: true, accedi: false };
    }
    if (rete.sessioneScaduta && rete.inAttesa > 0) {
        return {
            testo: `Sessione scaduta: accedi di nuovo per inviare ${rete.inAttesa} ${rete.inAttesa === 1 ? 'presenza' : 'presenze'}. Sono al sicuro sul telefono.`,
            classe: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
            chiudibile: false,
            accedi: true,
        };
    }
    if (rete.inAttesa > 0) {
        const quante = `${rete.inAttesa} ${rete.inAttesa === 1 ? 'presenza' : 'presenze'}`;

        return {
            testo: rete.offline ? `Senza connessione: ${quante} da inviare. Sono salvate sul telefono.` : `Invio in corso… (${quante})`,
            classe: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
            chiudibile: false,
            accedi: false,
        };
    }
    if (rete.offline) {
        return { testo: 'Senza connessione: puoi continuare, le presenze verranno inviate appena torna il segnale.', classe: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200', chiudibile: false, accedi: false };
    }

    return { testo: 'Tutto salvato', classe: 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200', chiudibile: false, accedi: false };
});

// The chaperone starts at the first stop that is theirs; earlier stops are only landmarks.
const primaMia = computed(() => fermate.value.findIndex((fermata) => fermata.mia));

const titolo = computed(() => props.data.charAt(0).toUpperCase() + props.data.slice(1));
</script>

<template>
    <Head :title="`Oggi · ${linea.nome}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-2xl flex-col gap-5 p-4">
            <Heading :title="linea.nome" :description="titolo + (arrivo ? ` · arrivo alle ${arrivo}` : '')" />

            <!-- Always visible while scrolling: the chaperone must know whether a tap is safe. -->
            <div role="status" aria-live="polite" :class="['sticky top-2 z-10 flex items-center gap-3 rounded-md border px-4 py-2 text-sm font-medium', stato.classe]">
                <span class="flex-1">{{ stato.testo }}</span>
                <a v-if="stato.accedi" href="/login" class="underline">Accedi</a>
                <button v-if="stato.chiudibile" type="button" class="underline" @click="messaggio = null">Chiudi</button>
            </div>

            <p v-if="paginaVecchia" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                Questa schermata è di un altro giorno. Collegati a internet per aggiornarla: finché non lo fai non si può segnare nulla.
            </p>

            <p
                v-else-if="!modificaAperta && arrivo"
                class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
            >
                Sono passati più di {{ minuti_modifica }} minuti dall'arrivo ({{ arrivo }}): le presenze non si possono più modificare.
                <span v-if="rete.inAttesa > 0">Quelle già segnate e non ancora inviate verranno comunque inviate.</span>
            </p>

            <template v-for="(fermata, indice) in fermate" :key="fermata.id">
                <SchedaDestinazioneOggi
                    v-if="fermata.mia && fermata.destinazione"
                    v-model:arrivo="arrivoRegistrato"
                    :linea-id="linea.id"
                    :nome="fermata.nome"
                    :orario="fermata.orario"
                    :giorno="data_iso"
                    :modifica-aperta="modificaAperta"
                />

                <SchedaFermataOggi
                    v-else-if="fermata.mia || fermata.precedente"
                    :fermata="fermata"
                    :giorno="data_iso"
                    :inizio="fermata.mia && indice === primaMia"
                    :precedente="fermata.precedente"
                    :modifica-aperta="modificaAperta"
                />

                <div v-else class="flex items-baseline gap-3 rounded-lg border border-dashed px-4 py-3 text-muted-foreground">
                    <span class="tabular-nums">{{ fermata.orario }}</span>
                    <span>{{ fermata.nome }}</span>
                    <span class="ml-auto text-xs">prima della tua partenza</span>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
