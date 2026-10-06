<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import StatoPresenza from '@/components/StatoPresenza.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { chiamaJson } from '@/lib/chiamate';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface BambinoGiorno {
    id: number;
    nome: string;
    stato: boolean | null;
    temporaneo: boolean;
    registrata_da: string | null;
    // Time of day the state was marked (HH:MM).
    registrata_alle: string | null;
}

interface ArrivoGiorno {
    ora: string;
    da: string | null;
}

interface FermataGiorno {
    id: number;
    nome: string;
    orario: string;
    destinazione: boolean;
    accompagnatori: { nome: string; da: string | null }[];
    bambini: BambinoGiorno[];
}

interface LineaGiorno {
    id: number;
    nome: string;
    arrivo: string | null;
    chiusa: boolean;
    modificabile_fino: string | null;
    con_destinazione: boolean;
    arrivo_registrato: ArrivoGiorno | null;
    presenti: number;
    assenti: number;
    non_segnati: number;
    fermate: FermataGiorno[];
}

const props = defineProps<{
    data: string;
    data_estesa: string;
    oggi: boolean;
    precedente: string;
    successiva: string | null;
    puo_modificare: boolean;
    citte: { id: number; nome: string }[];
    citta_scelta: number | null;
    totali: { presenti: number; assenti: number; non_segnati: number };
    linee: LineaGiorno[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Presenze', href: '/presenze' }];

// A local copy: corrections by administrators change it at once, the server saves in the background.
const linee = ref<LineaGiorno[]>(JSON.parse(JSON.stringify(props.linee)));
watch(
    () => props.linee,
    (nuove) => {
        linee.value = JSON.parse(JSON.stringify(nuove));
    },
);

const conta = (linea: LineaGiorno, stato: boolean | null) =>
    linea.fermate.reduce((somma, fermata) => somma + fermata.bambini.filter((bambino) => bambino.stato === stato).length, 0);

const totali = computed(() => ({
    presenti: linee.value.reduce((somma, linea) => somma + conta(linea, true), 0),
    assenti: linee.value.reduce((somma, linea) => somma + conta(linea, false), 0),
    non_segnati: linee.value.reduce((somma, linea) => somma + conta(linea, null), 0),
}));

// Day and city navigation keep each other's choice.
const parametri = (data?: string) => ({
    ...(data ? { data } : {}),
    ...(props.citta_scelta && props.citte.length > 0 ? { citta: props.citta_scelta } : {}),
});

const giorno = ref(props.data);
const vaiAlGiorno = () => {
    if (giorno.value) {
        router.get(route('presenze.index'), parametri(giorno.value), { preserveScroll: true });
    }
};

const cittaSelezionata = ref<number | null>(props.citta_scelta);
const cambiaCitta = () => {
    router.get(route('presenze.index'), { data: props.data, citta: cittaSelezionata.value }, { preserveScroll: true });
};

// Administrators set a child's state: present, absent, or back to "not marked" (null).
const errore = ref<string | null>(null);
const inCorso = ref(0);

const imposta = async (fermata: FermataGiorno, bambino: BambinoGiorno, stato: boolean | null) => {
    if (bambino.stato === stato) {
        return;
    }

    const precedente = bambino.stato;
    bambino.stato = stato;
    errore.value = null;
    inCorso.value++;

    try {
        const risposta = await chiamaJson<{ registrata_da: string | null; registrata_alle: string | null }>(route('presenze.correggi'), {
            metodo: 'POST',
            corpo: { data: props.data, fermata_id: fermata.id, bambino_id: bambino.id, presente: stato },
        });

        if (!risposta.ok) {
            bambino.stato = precedente;
            errore.value = risposta.dati.message ?? 'Non è stato possibile salvare la correzione.';
        } else {
            // Show at once who corrected it and when.
            bambino.registrata_da = risposta.dati.registrata_da;
            bambino.registrata_alle = risposta.dati.registrata_alle;
        }
    } catch {
        bambino.stato = precedente;
        errore.value = 'Nessuna connessione: la correzione non è stata salvata.';
    } finally {
        inCorso.value--;
    }
};

// The line's arrival time: administrators can set it or clear it, on any day.
const oraArrivo = ref<Record<number, string>>({});
const sincronizzaOre = () => {
    oraArrivo.value = Object.fromEntries(linee.value.map((linea) => [linea.id, linea.arrivo_registrato?.ora ?? '']));
};
sincronizzaOre();
watch(() => props.linee, sincronizzaOre);

const impostaArrivo = async (linea: LineaGiorno, ora: string | null) => {
    errore.value = null;
    inCorso.value++;

    try {
        const risposta = await chiamaJson<ArrivoGiorno>(route('presenze.arrivo'), {
            metodo: 'POST',
            corpo: { data: props.data, linea_id: linea.id, ora },
        });

        if (!risposta.ok) {
            errore.value = risposta.dati.message ?? 'Non è stato possibile salvare l\'orario di arrivo.';

            return;
        }

        linea.arrivo_registrato = ora === null ? null : { ora: risposta.dati.ora, da: risposta.dati.da };
        oraArrivo.value[linea.id] = linea.arrivo_registrato?.ora ?? '';
    } catch {
        errore.value = 'Nessuna connessione: l\'orario di arrivo non è stato salvato.';
    } finally {
        inCorso.value--;
    }
};

const maiuscola = (testo: string) => testo.charAt(0).toUpperCase() + testo.slice(1);
const oggiIso = new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD in local time
const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head title="Presenze" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-4xl flex-col gap-6 p-4">
            <Heading
                :title="maiuscola(data_estesa)"
                :description="puo_modificare ? 'Come amministratore puoi correggere le presenze di qualsiasi giorno, anche dopo la chiusura.' : oggi ? 'Oggi · sola lettura' : 'Sola lettura'"
            />

            <!-- City choice, for global administrators -->
            <div v-if="citte.length > 0" class="flex items-center gap-2">
                <label for="citta" class="text-sm font-medium">Città</label>
                <select id="citta" v-model="cittaSelezionata" :class="selectClass" @change="cambiaCitta">
                    <option v-for="citta in citte" :key="citta.id" :value="citta.id">{{ citta.nome }}</option>
                </select>
            </div>

            <!-- Day navigation -->
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" as-child>
                    <Link :href="route('presenze.index', parametri(precedente))" preserve-scroll>← Giorno prima</Link>
                </Button>
                <Button v-if="successiva" variant="outline" as-child>
                    <Link :href="route('presenze.index', parametri(successiva))" preserve-scroll>Giorno dopo →</Link>
                </Button>
                <Button v-if="!oggi" variant="ghost" as-child>
                    <Link :href="route('presenze.index', parametri())" preserve-scroll>Torna a oggi</Link>
                </Button>
                <Input v-model="giorno" type="date" :max="oggiIso" class="ml-auto w-44" aria-label="Vai a un giorno" @change="vaiAlGiorno" />
            </div>

            <div v-if="errore" role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {{ errore }}
            </div>

            <!-- Totals -->
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border p-4">
                    <p class="text-3xl font-semibold text-green-700 dark:text-green-400">{{ totali.presenti }}</p>
                    <p class="text-sm text-muted-foreground">Presenti</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-3xl font-semibold text-red-700 dark:text-red-400">{{ totali.assenti }}</p>
                    <p class="text-sm text-muted-foreground">Assenti</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-3xl font-semibold text-amber-700 dark:text-amber-400">{{ totali.non_segnati }}</p>
                    <p class="text-sm text-muted-foreground">{{ oggi ? 'Da segnare' : 'Non segnati' }}</p>
                </div>
            </div>

            <div v-if="linee.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                Non ci sono linee di cui vedere le presenze.
            </div>

            <!-- One block per line -->
            <details v-for="linea in linee" :key="linea.id" :open="linee.length === 1" class="group rounded-xl border">
                <summary class="flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-1 p-4">
                    <span class="text-lg font-semibold">{{ linea.nome }}</span>
                    <span class="text-sm text-muted-foreground">
                        <template v-if="linea.arrivo">arrivo {{ linea.arrivo }} · </template>
                        <template v-if="oggi && !linea.chiusa && linea.modificabile_fino">gli accompagnatori possono segnare fino alle {{ linea.modificabile_fino }}</template>
                        <template v-else>chiusa agli accompagnatori</template>
                    </span>
                    <span v-if="linea.con_destinazione" class="text-sm" :class="linea.arrivo_registrato ? 'text-green-700 dark:text-green-400' : 'text-muted-foreground'">
                        {{ linea.arrivo_registrato ? `arrivati alle ${linea.arrivo_registrato.ora}` : 'arrivo non segnato' }}
                    </span>
                    <span class="ml-auto flex gap-3 text-sm">
                        <span class="text-green-700 dark:text-green-400">{{ conta(linea, true) }} presenti</span>
                        <span class="text-red-700 dark:text-red-400">{{ conta(linea, false) }} assenti</span>
                        <span class="text-amber-700 dark:text-amber-400">{{ conta(linea, null) }} {{ oggi && !linea.chiusa ? 'da segnare' : 'non segnati' }}</span>
                    </span>
                </summary>

                <div class="space-y-5 border-t p-4">
                    <p v-if="linea.fermate.length === 0" class="text-sm text-muted-foreground">Questa linea non ha ancora fermate.</p>

                    <div v-if="linea.con_destinazione" class="rounded-lg border bg-muted/30 p-3 text-sm">
                        <p class="font-medium">Arrivo alla destinazione</p>
                        <p v-if="linea.arrivo_registrato" class="text-green-700 dark:text-green-400">
                            Arrivati alle {{ linea.arrivo_registrato.ora }}<span v-if="linea.arrivo_registrato.da"> · segnato da {{ linea.arrivo_registrato.da }}</span>
                        </p>
                        <p v-else class="text-muted-foreground">Nessun arrivo segnato per questo giorno.</p>

                        <div v-if="puo_modificare" class="mt-2 flex flex-wrap items-center gap-2">
                            <Input v-model="oraArrivo[linea.id]" type="time" class="w-32" :aria-label="`Orario di arrivo di ${linea.nome}`" />
                            <Button type="button" size="sm" variant="outline" :disabled="!oraArrivo[linea.id]" @click="impostaArrivo(linea, oraArrivo[linea.id])">
                                Salva l'orario
                            </Button>
                            <Button type="button" size="sm" variant="ghost" :disabled="!linea.arrivo_registrato" @click="impostaArrivo(linea, null)">Rimuovi</Button>
                        </div>
                    </div>

                    <section v-for="fermata in linea.fermate" :key="fermata.id" class="space-y-2">
                        <h3 class="font-medium">
                            <span class="tabular-nums">{{ fermata.orario }}</span> · {{ fermata.nome }}
                        </h3>

                        <p class="text-sm text-muted-foreground">
                            <template v-if="fermata.accompagnatori.length === 0">Nessun accompagnatore</template>
                            <template v-else>
                                Con il gruppo:
                                <span v-for="(persona, indice) in fermata.accompagnatori" :key="persona.nome">
                                    {{ persona.nome }}<span v-if="persona.da"> (da «{{ persona.da }}»)</span><span v-if="indice < fermata.accompagnatori.length - 1">, </span>
                                </span>
                            </template>
                        </p>

                        <p v-if="fermata.destinazione" class="rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground">
                            Destinazione: nessun bambino sale qui.
                        </p>

                        <p v-else-if="fermata.bambini.length === 0" class="rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground">Nessun bambino.</p>

                        <ul v-else class="divide-y rounded-lg border text-sm">
                            <li v-for="bambino in fermata.bambini" :key="bambino.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
                                <span class="font-medium">{{ bambino.nome }}</span>
                                <span v-if="bambino.temporaneo" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-950 dark:text-sky-200">Solo oggi</span>
                                <span class="ml-auto flex flex-wrap items-center gap-3">
                                    <span v-if="bambino.registrata_da || bambino.registrata_alle" class="text-xs text-muted-foreground">
                                        segnato<template v-if="bambino.registrata_da"> da {{ bambino.registrata_da }}</template><template v-if="bambino.registrata_alle"> alle {{ bambino.registrata_alle }}</template>
                                    </span>
                                    <StatoPresenza :stato="bambino.stato" />
                                    <span v-if="puo_modificare" class="flex gap-1" role="group" :aria-label="`Correggi ${bambino.nome}`">
                                        <Button type="button" size="sm" variant="outline" :aria-pressed="bambino.stato === true" @click="imposta(fermata, bambino, true)">Presente</Button>
                                        <Button type="button" size="sm" variant="outline" :aria-pressed="bambino.stato === false" @click="imposta(fermata, bambino, false)">Assente</Button>
                                        <Button type="button" size="sm" variant="ghost" :disabled="bambino.stato === null" @click="imposta(fermata, bambino, null)">Azzera</Button>
                                    </span>
                                </span>
                            </li>
                        </ul>
                    </section>
                </div>
            </details>
        </div>
    </AppLayout>
</template>
