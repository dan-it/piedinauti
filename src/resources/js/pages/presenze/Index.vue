<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import StatoPresenza from '@/components/StatoPresenza.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface BambinoGiorno {
    id: number;
    nome: string;
    stato: boolean | null;
    temporaneo: boolean;
    registrata_da: string | null;
}

interface FermataGiorno {
    id: number;
    nome: string;
    orario: string;
    accompagnatori: { nome: string; da: string | null }[];
    bambini: BambinoGiorno[];
}

interface LineaGiorno {
    id: number;
    nome: string;
    arrivo: string | null;
    chiusa: boolean;
    modificabile_fino: string | null;
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
    totali: { presenti: number; assenti: number; non_segnati: number };
    linee: LineaGiorno[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Presenze', href: '/presenze' }];

// Jump to any day up to today.
const giorno = ref(props.data);
const vaiAlGiorno = () => {
    if (giorno.value) {
        router.get(route('presenze.index'), { data: giorno.value }, { preserveScroll: true });
    }
};

const maiuscola = (testo: string) => testo.charAt(0).toUpperCase() + testo.slice(1);
const oggiIso = new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD in local time
</script>

<template>
    <Head title="Presenze" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-4xl flex-col gap-6 p-4">
            <Heading :title="maiuscola(data_estesa)" :description="oggi ? 'Oggi' : 'Sola lettura: le presenze dei giorni passati non si modificano.'" />

            <!-- Day navigation -->
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" as-child>
                    <Link :href="route('presenze.index', { data: precedente })" preserve-scroll>← Giorno prima</Link>
                </Button>
                <Button v-if="successiva" variant="outline" as-child>
                    <Link :href="route('presenze.index', { data: successiva })" preserve-scroll>Giorno dopo →</Link>
                </Button>
                <Button v-if="!oggi" variant="ghost" as-child>
                    <Link :href="route('presenze.index')" preserve-scroll>Torna a oggi</Link>
                </Button>
                <Input v-model="giorno" type="date" :max="oggiIso" class="ml-auto w-44" aria-label="Vai a un giorno" @change="vaiAlGiorno" />
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
                        <template v-if="oggi && !linea.chiusa && linea.modificabile_fino">si può segnare fino alle {{ linea.modificabile_fino }}</template>
                        <template v-else>chiusa</template>
                    </span>
                    <span class="ml-auto flex gap-3 text-sm">
                        <span class="text-green-700 dark:text-green-400">{{ linea.presenti }} presenti</span>
                        <span class="text-red-700 dark:text-red-400">{{ linea.assenti }} assenti</span>
                        <span class="text-amber-700 dark:text-amber-400">{{ linea.non_segnati }} {{ oggi && !linea.chiusa ? 'da segnare' : 'non segnati' }}</span>
                    </span>
                </summary>

                <div class="space-y-5 border-t p-4">
                    <p v-if="linea.fermate.length === 0" class="text-sm text-muted-foreground">Questa linea non ha ancora fermate.</p>

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

                        <p v-if="fermata.bambini.length === 0" class="rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground">Nessun bambino.</p>

                        <ul v-else class="divide-y rounded-lg border text-sm">
                            <li v-for="bambino in fermata.bambini" :key="bambino.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                <span class="font-medium">{{ bambino.nome }}</span>
                                <span v-if="bambino.temporaneo" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-950 dark:text-sky-200">Solo oggi</span>
                                <span class="ml-auto flex items-center gap-3">
                                    <span v-if="bambino.registrata_da" class="text-xs text-muted-foreground">segnato da {{ bambino.registrata_da }}</span>
                                    <StatoPresenza :stato="bambino.stato" />
                                </span>
                            </li>
                        </ul>
                    </section>
                </div>
            </details>
        </div>
    </AppLayout>
</template>
