<script setup lang="ts">
import BarreGiornaliere from '@/components/BarreGiornaliere.vue';
import FiltroReport from '@/components/FiltroReport.vue';
import Heading from '@/components/Heading.vue';
import SchedeReport from '@/components/SchedeReport.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type FiltriReport, giornoBreve, italiana, percentuale, scartoMedioTesto, scartoTesto } from '@/lib/report';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface RigaLinea {
    id: number;
    nome: string;
    giorni: number;
    presenti: number;
    assenti: number;
    bambini: number;
    frequenza: number | null;
    puntualita: { arrivi: number; scarto_medio: number | null } | null;
}

interface RigaGiorno {
    data: string;
    presenti: number;
    assenti: number;
    arrivo: string | null;
    scarto_minuti: number | null;
}

const props = defineProps<
    FiltriReport & {
        linee: { id: number; nome: string }[];
        totali: { presenti: number; assenti: number; frequenza: number | null; giorni: number };
        linee_riepilogo: RigaLinea[];
        giorni: RigaGiorno[];
    }
>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Report', href: '/report' }];

const filtri = computed<FiltriReport>(() => ({ da: props.da, a: props.a, linea_scelta: props.linea_scelta, citta_scelta: props.citta_scelta, citte: props.citte }));

const applica = (scelti: { da: string; a: string; linea: number | null; citta: number | null }) => {
    router.get(
        route('report.index'),
        { da: scelti.da, a: scelti.a, ...(scelti.linea ? { linea: scelti.linea } : {}), ...(props.citte.length > 0 && scelti.citta ? { citta: scelti.citta } : {}) },
        { preserveScroll: true },
    );
};

const conArrivi = computed(() => props.giorni.some((giorno) => giorno.arrivo !== null));
const nomeLineaScelta = computed(() => props.linee.find((linea) => linea.id === props.linea_scelta)?.nome ?? 'tutte le linee');
</script>

<template>
    <Head title="Report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-5xl flex-col gap-6 p-4">
            <Heading title="Report" :description="`Dal ${italiana(da)} al ${italiana(a)}`" />

            <FiltroReport :da="da" :a="a" :linea-scelta="linea_scelta" :linee="linee" :citte="citte" :citta-scelta="citta_scelta" @applica="applica" />

            <SchedeReport attiva="linee" :filtri="filtri" />

            <!-- Totals -->
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
                    <p class="text-sm text-muted-foreground">Giorni con dati</p>
                </div>
            </div>

            <p class="text-sm text-muted-foreground">
                La frequenza conta solo i giorni in cui il bambino è stato segnato: un giorno senza segnatura non è né una presenza né un'assenza.
            </p>

            <!-- By line -->
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Per linea</h2>

                <p v-if="linee_riepilogo.length === 0" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Non ci sono linee di cui vedere i report.
                </p>

                <div v-else class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Linea</th>
                                <th class="px-4 py-3 text-right font-medium">Giorni</th>
                                <th class="px-4 py-3 text-right font-medium">Bambini</th>
                                <th class="px-4 py-3 text-right font-medium">Presenze</th>
                                <th class="px-4 py-3 text-right font-medium">Assenze</th>
                                <th class="px-4 py-3 text-right font-medium">Frequenza</th>
                                <th class="px-4 py-3 font-medium">Arrivo (media)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="riga in linee_riepilogo" :key="riga.id" class="border-t">
                                <td class="px-4 py-3 font-medium">{{ riga.nome }}</td>
                                <td class="px-4 py-3 text-right">{{ riga.giorni }}</td>
                                <td class="px-4 py-3 text-right">{{ riga.bambini }}</td>
                                <td class="px-4 py-3 text-right">{{ riga.presenti }}</td>
                                <td class="px-4 py-3 text-right">{{ riga.assenti }}</td>
                                <td class="px-4 py-3 text-right">{{ percentuale(riga.frequenza) }}</td>
                                <td class="px-4 py-3">
                                    <template v-if="riga.puntualita">
                                        {{ scartoMedioTesto(riga.puntualita.scarto_medio) }}
                                        <span class="text-muted-foreground">({{ riga.puntualita.arrivi }} {{ riga.puntualita.arrivi === 1 ? 'giorno' : 'giorni' }})</span>
                                    </template>
                                    <span v-else class="text-muted-foreground">nessuna destinazione</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Day by day -->
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">Giorno per giorno · {{ nomeLineaScelta }}</h2>

                <p v-if="giorni.length === 0" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Nessuna presenza segnata in questo periodo.
                </p>

                <template v-else>
                    <BarreGiornaliere :giorni="giorni" />

                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-3 font-medium">Giorno</th>
                                    <th class="px-4 py-3 text-right font-medium">Presenti</th>
                                    <th class="px-4 py-3 text-right font-medium">Assenti</th>
                                    <th v-if="conArrivi" class="px-4 py-3 font-medium">Arrivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="riga in giorni" :key="riga.data" class="border-t">
                                    <td class="px-4 py-3 font-medium">{{ giornoBreve(riga.data) }}</td>
                                    <td class="px-4 py-3 text-right">{{ riga.presenti }}</td>
                                    <td class="px-4 py-3 text-right">{{ riga.assenti }}</td>
                                    <td v-if="conArrivi" class="px-4 py-3">
                                        <template v-if="riga.arrivo">
                                            {{ riga.arrivo }} <span class="text-muted-foreground">· {{ scartoTesto(riga.scarto_minuti) }}</span>
                                        </template>
                                        <span v-else class="text-muted-foreground">non segnato</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="linea_scelta === null" class="text-sm text-muted-foreground">Per vedere gli orari di arrivo scegli una linea.</p>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
