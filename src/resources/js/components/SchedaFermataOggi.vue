<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { chiamaJson } from '@/lib/chiamate';
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
    // True for the stop where the chaperone starts: from here on they stay with the group.
    inizio?: boolean;
    // False once 30 minutes have passed since the line's arrival: nothing can be changed any more.
    modificaAperta: boolean;
}>();

// The parent shows one overall "saving / saved / error" indicator.
const emit = defineEmits<{
    inizio: [];
    fine: [errore: string | null];
}>();

const presenti = () => props.fermata.bambini.filter((bambino) => bambino.presente === true).length;
const assenti = () => props.fermata.bambini.filter((bambino) => bambino.presente === false).length;
const daSegnare = () => props.fermata.bambini.filter((bambino) => bambino.presente === null).length;

// One tap = one request. The screen changes at once and goes back if the save fails.
const segna = async (bambino: BambinoOggi, presente: boolean) => {
    if (!bambino.modificabile || bambino.presente === presente) {
        return;
    }

    const precedente = bambino.presente;
    bambino.presente = presente;
    emit('inizio');

    try {
        const risposta = await chiamaJson<{ temporaneo: boolean }>(route('oggi.presenze', props.fermata.id), {
            metodo: 'POST',
            corpo: { bambino_id: bambino.id, presente },
        });

        if (!risposta.ok) {
            bambino.presente = precedente;
            // 403 = the correction window has closed: stop offering the buttons for this child.
            if (risposta.stato === 403) {
                bambino.modificabile = false;
            }
            emit('fine', risposta.dati.message ?? 'Non è stato possibile salvare. Riprova.');

            return;
        }

        bambino.altrove = null;
        emit('fine', null);
    } catch {
        bambino.presente = precedente;
        emit('fine', 'Nessuna connessione: la presenza non è stata salvata. Riprova.');
    }
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
        try {
            const risposta = await chiamaJson<{ risultati: Risultato[] }>(route('oggi.cerca', props.fermata.id) + '?q=' + encodeURIComponent(testo.value.trim()));
            risultati.value = risposta.ok ? risposta.dati.risultati : [];
        } catch {
            risultati.value = [];
        } finally {
            cercando.value = false;
        }
    }, 300);
};

onBeforeUnmount(() => clearTimeout(attesa));

const aggiungi = async (trovato: Risultato) => {
    emit('inizio');

    try {
        const risposta = await chiamaJson<{ temporaneo: boolean }>(route('oggi.presenze', props.fermata.id), {
            metodo: 'POST',
            corpo: { bambino_id: trovato.id, presente: true },
        });

        if (!risposta.ok) {
            emit('fine', risposta.dati.message ?? 'Non è stato possibile aggiungere il bambino. Riprova.');

            return;
        }

        props.fermata.bambini.push({
            id: trovato.id,
            nome: trovato.nome,
            presente: true,
            temporaneo: risposta.dati.temporaneo,
            altrove: null,
            modificabile: true,
        });
        risultati.value = risultati.value.filter((altro) => altro.id !== trovato.id);
        emit('fine', null);
    } catch {
        emit('fine', 'Nessuna connessione: il bambino non è stato aggiunto. Riprova.');
    }
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
                        :disabled="!bambino.modificabile"
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
                        :disabled="!bambino.modificabile"
                        :aria-pressed="bambino.presente === false"
                        @click="segna(bambino, false)"
                    >
                        <X class="h-5 w-5" /> Assente
                    </Button>
                </div>

                <p v-if="!bambino.modificabile" class="mt-2 text-sm text-muted-foreground">Tempo scaduto: la presenza non si può più modificare.</p>
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
