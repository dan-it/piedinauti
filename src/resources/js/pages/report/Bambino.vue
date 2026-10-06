<script setup lang="ts">
import FiltroReport from '@/components/FiltroReport.vue';
import Heading from '@/components/Heading.vue';
import StatoPresenza from '@/components/StatoPresenza.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type FiltriReport, giornoBreve, italiana, parametri, percentuale } from '@/lib/report';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Registro {
    id: number;
    data: string;
    linea: string | null;
    fermata: string | null;
    stato: boolean;
    temporaneo: boolean;
    registrata_da: string | null;
    registrata_alle: string | null;
}

const props = defineProps<
    FiltriReport & {
        linee: { id: number; nome: string }[];
        bambino: { id: number; nome: string };
        totali: { giorni: number; presenti: number; assenti: number; temporanei: number; frequenza: number | null };
        registri: Registro[];
    }
>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Report', href: '/report' },
    { title: 'Per bambino', href: route('report.bambini', parametri(props)) },
    { title: props.bambino.nome, href: '#' },
];

const filtri = computed<FiltriReport>(() => ({ da: props.da, a: props.a, linea_scelta: props.linea_scelta, citta_scelta: props.citta_scelta, citte: props.citte }));

const applica = (scelti: { da: string; a: string; linea: number | null; citta: number | null }) => {
    router.get(
        route('report.bambino', props.bambino.id),
        { da: scelti.da, a: scelti.a, ...(scelti.linea ? { linea: scelti.linea } : {}), ...(props.citte.length > 0 && scelti.citta ? { citta: scelti.citta } : {}) },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="`Report · ${bambino.nome}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-4xl flex-col gap-6 p-4">
            <Heading :title="bambino.nome" :description="`Dal ${italiana(da)} al ${italiana(a)}`" />

            <FiltroReport :da="da" :a="a" :linea-scelta="linea_scelta" :linee="linee" :citte="citte" :citta-scelta="citta_scelta" @applica="applica" />

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border p-4">
                    <p class="text-3xl font-semibold text-green-700 dark:text-green-400">{{ totali.presenti }}</p>
                    <p class="text-sm text-muted-foreground">Presenze</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-3xl font-semibold text-red-700 dark:text-red-400">{{ totali.assenti }}</p>
                    <p class="text-sm text-muted-foreground">Assenze</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-3xl font-semibold">{{ percentuale(totali.frequenza) }}</p>
                    <p class="text-sm text-muted-foreground">Frequenza</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-3xl font-semibold">{{ totali.giorni }}</p>
                    <p class="text-sm text-muted-foreground">Giorni segnati</p>
                </div>
            </div>

            <p v-if="registri.length === 0" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                Nessuna presenza segnata in questo periodo.
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Giorno</th>
                            <th class="px-4 py-3 font-medium">Linea e fermata</th>
                            <th class="px-4 py-3 font-medium">Stato</th>
                            <th class="px-4 py-3 font-medium">Segnato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="riga in registri" :key="riga.id" class="border-t">
                            <td class="px-4 py-3 font-medium">{{ giornoBreve(riga.data) }}</td>
                            <td class="px-4 py-3">
                                {{ riga.linea }} · {{ riga.fermata }}
                                <span v-if="riga.temporaneo" class="ml-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-950 dark:text-sky-200">Solo quel giorno</span>
                            </td>
                            <td class="px-4 py-3"><StatoPresenza :stato="riga.stato" /></td>
                            <td class="px-4 py-3 text-muted-foreground">
                                <template v-if="riga.registrata_da">{{ riga.registrata_da }}</template>
                                <template v-if="riga.registrata_alle"> alle {{ riga.registrata_alle }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Button variant="ghost" class="self-start" as-child>
                <Link :href="route('report.bambini', parametri(filtri))">← Torna all'elenco dei bambini</Link>
            </Button>
        </div>
    </AppLayout>
</template>
