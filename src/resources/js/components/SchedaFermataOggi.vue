<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { chiamaJson } from '@/lib/chiamate';
import { nuovaAzione } from '@/lib/coda';
import { cercaLocale } from '@/lib/elencoBambini';
import { accoda, rete } from '@/lib/rete';
import { Check, Plus, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';

export interface BambinoOggi {
    id: number;
    nome: string;
    presente: boolean | null;
    temporaneo: boolean;
    altrove: string | null;
    modificabile: boolean;
}

export interface FermataOggi {
    id: number;
    nome: string;
    orario: string;
    mia: boolean;
    bambini: BambinoOggi[];
}

interface Risultato {
    id: number;
    nome: string;
    altrove: string | null;
}

const props = defineProps<{
    fermata: FermataOggi;
    // The day this screen is about (YYYY-MM-DD): kept with each tap so it can be shown again after a reload.
    giorno: string;
    // True for the stop where the chaperone starts: from here on they stay with the group.
    inizio?: boolean;
    // True for a stop before the chaperone's start that the line lets them work on.
    precedente?: boolean;
    // False once 30 minutes have passed since the line's arrival: nothing can be changed any more.
    modificaAperta: boolean;
}>();

const presenti = () => props.fermata.bambini.filter((bambino) => bambino.presente === true).length;
const assenti = () => props.fermata.bambini.filter((bambino) => bambino.presente === false).length;
const daSegnare = () => props.fermata.bambini.filter((bambino) => bambino.presente === null).length;

// One tap: the screen changes at once and the tap goes into the phone's outbox with the moment it
// was made. It is sent now if there is signal, or later if not; the screen keeps working either way.
const segna = async (bambino: BambinoOggi, presente: boolean) => {
    if (!props.modificaAperta || !bambino.modificabile || bambino.presente === presente) {
        return;
    }

    bambino.presente = presente;
    bambino.altrove = null;

    await accoda(
        nuovaAzione({
            chiave: `p:${props.fermata.id}:${bambino.id}`,
            tipo: 'presenza',
            url: route('oggi.presenze', props.fermata.id),
            corpo: { bambino_id: bambino.id, presente, registrata_il: new Date().toISOString() },
            riferimento: { giorno: props.giorno, fermataId: props.fermata.id, bambinoId: bambino.id, presente },
        }),
    );
};

// Adding a child for today only: search the city's children and mark the chosen one present.
const ricercaAperta = ref(false);
const testo = ref('');
const risultati = ref<Risultato[]>([]);
const cercando = ref(false);
let attesa: ReturnType<typeof setTimeout> | undefined;

const cerca = () => {
    clearTimeout(attesa);

    if (testo.value.trim().length < 2) {
        risultati.value = [];

        return;
    }

    attesa = setTimeout(async () => {
        cercando.value = true;
        const esclusi = new Set(props.fermata.bambini.map((bambino) => bambino.id));

        try {
            // With signal the server answers (it also tells who is already at another stop today);
            // without, the list saved on the phone does.
            if (rete.offline || !navigator.onLine) {
                risultati.value = (await cercaLocale(testo.value, esclusi)).map((bambino) => ({ ...bambino, altrove: null }));

                return;
            }

            const risposta = await chiamaJson<{ risultati: Risultato[] }>(route('oggi.cerca', props.fermata.id) + '?q=' + encodeURIComponent(testo.value.trim()));

            risultati.value = risposta.ok
                ? risposta.dati.risultati
                : (await cercaLocale(testo.value, esclusi)).map((bambino) => ({ ...bambino, altrove: null }));
        } catch {
            risultati.value = (await cercaLocale(testo.value, esclusi)).map((bambino) => ({ ...bambino, altrove: null }));
        } finally {
            cercando.value = false;
        }
    }, 300);
};

onBeforeUnmount(() => clearTimeout(attesa));

const aggiungi = async (trovato: Risultato) => {
    props.fermata.bambini.push({
        id: trovato.id,
        nome: trovato.nome,
        presente: true,
        temporaneo: true,
        altrove: null,
        modificabile: true,
    });
    risultati.value = risultati.value.filter((altro) => altro.id !== trovato.id);

    await accoda(
        nuovaAzione({
            chiave: `p:${props.fermata.id}:${trovato.id}`,
            tipo: 'presenza',
            url: route('oggi.presenze', props.fermata.id),
            corpo: { bambino_id: trovato.id, presente: true, registrata_il: new Date().toISOString() },
            // The name is kept so the child can be shown again after a reload while the tap is still waiting.
            riferimento: { giorno: props.giorno, fermataId: props.fermata.id, bambinoId: trovato.id, presente: true, nome: trovato.nome, temporaneo: true },
        }),
    );
};

const chiudiRicerca = () => {
    ricercaAperta.value = false;
    testo.value = '';
    risultati.value = [];
};
</script>

<template>
    <section class="space-y-4 rounded-xl border-2 border-primary/40 p-4">
        <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="text-xl font-semibold">
                <span class="tabular-nums">{{ fermata.orario }}</span> · {{ fermata.nome }}
                <span v-if="inizio" class="ml-2 rounded-full bg-primary px-2 py-0.5 align-middle text-xs font-medium text-primary-foreground">Qui inizi</span>
                <span v-else-if="precedente" class="ml-2 rounded-full bg-muted px-2 py-0.5 align-middle text-xs font-medium text-muted-foreground">Prima della tua partenza</span>
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ presenti() }} presenti · {{ assenti() }} assenti<span v-if="daSegnare() > 0"> · {{ daSegnare() }} da segnare</span>
            </p>
        </header>

        <p v-if="fermata.bambini.length === 0" class="rounded-lg border border-dashed p-4 text-center text-muted-foreground">
            Nessun bambino assegnato a questa fermata.
        </p>

        <ul v-else class="space-y-3">
            <li v-for="bambino in fermata.bambini" :key="bambino.id" class="rounded-lg border p-3">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="text-lg font-medium">{{ bambino.nome }}</span>
                    <span v-if="bambino.temporaneo" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-950 dark:text-sky-200">Solo oggi</span>
                    <span v-if="bambino.altrove" class="text-sm text-amber-700 dark:text-amber-400">Già segnato alla fermata «{{ bambino.altrove }}»</span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <Button
                        type="button"
                        size="lg"
                        :variant="bambino.presente === true ? 'default' : 'outline'"
                        :class="['h-14 text-base', bambino.presente === true ? 'bg-green-600 text-white hover:bg-green-600' : '']"
                        :disabled="!modificaAperta || !bambino.modificabile"
                        :aria-pressed="bambino.presente === true"
                        @click="segna(bambino, true)"
                    >
                        <Check class="h-5 w-5" /> Presente
                    </Button>
                    <Button
                        type="button"
                        size="lg"
                        :variant="bambino.presente === false ? 'default' : 'outline'"
                        :class="['h-14 text-base', bambino.presente === false ? 'bg-red-600 text-white hover:bg-red-600' : '']"
                        :disabled="!modificaAperta || !bambino.modificabile"
                        :aria-pressed="bambino.presente === false"
                        @click="segna(bambino, false)"
                    >
                        <X class="h-5 w-5" /> Assente
                    </Button>
                </div>

                <p v-if="!modificaAperta || !bambino.modificabile" class="mt-2 text-sm text-muted-foreground">Tempo scaduto: la presenza non si può più modificare.</p>
            </li>
        </ul>

        <div v-if="modificaAperta" class="space-y-3">
            <Button v-if="!ricercaAperta" type="button" variant="outline" size="lg" class="h-12 w-full text-base" @click="ricercaAperta = true">
                <Plus class="h-5 w-5" /> Aggiungi un bambino per oggi
            </Button>

            <div v-else class="space-y-3 rounded-lg bg-muted/40 p-3">
                <div class="flex gap-2">
                    <Input
                        v-model="testo"
                        type="search"
                        autofocus
                        placeholder="Nome del bambino (almeno 2 lettere)"
                        aria-label="Cerca un bambino da aggiungere per oggi"
                        class="h-12 text-base"
                        @input="cerca"
                    />
                    <Button type="button" variant="ghost" size="lg" class="h-12" @click="chiudiRicerca">Chiudi</Button>
                </div>

                <p v-if="rete.offline" class="text-sm text-muted-foreground">Senza connessione: cerco nell'elenco salvato sul telefono.</p>
                <p v-if="cercando" class="text-sm text-muted-foreground">Cerco…</p>
                <p v-else-if="testo.trim().length >= 2 && risultati.length === 0" class="text-sm text-muted-foreground">
                    Nessun bambino trovato (o è già nell'elenco di questa fermata).
                </p>

                <ul v-if="risultati.length > 0" class="space-y-2">
                    <li v-for="trovato in risultati" :key="trovato.id" class="flex items-center justify-between gap-3 rounded-lg border bg-background p-3">
                        <span class="text-base">
                            {{ trovato.nome }}
                            <span v-if="trovato.altrove" class="block text-sm text-amber-700 dark:text-amber-400">Oggi già alla fermata «{{ trovato.altrove }}»: verrà spostato qui</span>
                        </span>
                        <Button type="button" size="lg" class="h-12" @click="aggiungi(trovato)"><Plus class="h-5 w-5" /> Aggiungi</Button>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
