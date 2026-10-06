<script setup lang="ts">
import FiltroReport from '@/components/FiltroReport.vue';
import Heading from '@/components/Heading.vue';
import SchedeReport from '@/components/SchedeReport.vue';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type FiltriReport, italiana, parametri, percentuale } from '@/lib/report';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface RigaBambino {
    id: number;
    nome: string;
    giorni: number;
    presenti: number;
    assenti: number;
    temporanei: number;
    frequenza: number | null;
}

const props = defineProps<
    FiltriReport & {
        linee: { id: number; nome: string }[];
        bambini: RigaBambino[];
    }
>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Report', href: '/report' },
    { title: 'Per bambino', href: '#' },
];

const filtri = computed<FiltriReport>(() => ({ da: props.da, a: props.a, linea_scelta: props.linea_scelta, citta_scelta: props.citta_scelta, citte: props.citte }));

const applica = (scelti: { da: string; a: string; linea: number | null; citta: number | null }) => {
    router.get(
        route('report.bambini'),
        { da: scelti.da, a: scelti.a, ...(scelti.linea ? { linea: scelti.linea } : {}), ...(props.citte.length > 0 && scelti.citta ? { citta: scelti.citta } : {}) },
        { preserveScroll: true },
    );
};

// Filter by name as you type (the list is already on the page).
const ricerca = ref('');
const normalizza = (testo: string) => testo.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const visibili = computed(() => {
    const parole = normalizza(ricerca.value.trim()).split(/\s+/).filter(Boolean);

    return props.bambini.filter((bambino) => parole.every((parola) => normalizza(bambino.nome).includes(parola)));
});
</script>

<template>
    <Head title="Report per bambino" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-5xl flex-col gap-6 p-4">
            <Heading title="Report per bambino" :description="`Dal ${italiana(da)} al ${italiana(a)}`" />

            <FiltroReport :da="da" :a="a" :linea-scelta="linea_scelta" :linee="linee" :citte="citte" :citta-scelta="citta_scelta" @applica="applica" />

            <SchedeReport attiva="bambini" :filtri="filtri" />

            <Input v-model="ricerca" type="search" placeholder="Cerca un bambino" class="max-w-sm" aria-label="Cerca un bambino" />

            <p v-if="bambini.length === 0" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                Nessun bambino in questo periodo.
            </p>

            <p v-else-if="visibili.length === 0" class="text-sm text-muted-foreground">Nessun bambino corrisponde alla ricerca.</p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Bambino</th>
                            <th class="px-4 py-3 text-right font-medium">Giorni segnati</th>
                            <th class="px-4 py-3 text-right font-medium">Presenze</th>
                            <th class="px-4 py-3 text-right font-medium">Assenze</th>
                            <th class="px-4 py-3 text-right font-medium">Solo quel giorno</th>
                            <th class="px-4 py-3 text-right font-medium">Frequenza</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="riga in visibili" :key="riga.id" class="border-t">
                            <td class="px-4 py-3 font-medium">
                                <Link :href="route('report.bambino', { bambino: riga.id, ...parametri(filtri) })" class="underline-offset-4 hover:underline">{{ riga.nome }}</Link>
                            </td>
                            <td class="px-4 py-3 text-right">{{ riga.giorni }}</td>
                            <td class="px-4 py-3 text-right">{{ riga.presenti }}</td>
                            <td class="px-4 py-3 text-right">{{ riga.assenti }}</td>
                            <td class="px-4 py-3 text-right">{{ riga.temporanei }}</td>
                            <td class="px-4 py-3 text-right">{{ percentuale(riga.frequenza) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-sm text-muted-foreground">
                Sono elencati i bambini segnati nel periodo e quelli assegnati alle linee, anche se non segnati mai. La frequenza conta solo i giorni segnati.
            </p>
        </div>
    </AppLayout>
</template>
