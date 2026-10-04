<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import SchedaFermataOggi, { type FermataOggi } from '@/components/SchedaFermataOggi.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    linea: { id: number; nome: string };
    data: string;
    fermate: FermataOggi[];
    arrivo: string | null;
    modifica_aperta: boolean;
    minuti_modifica: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Oggi', href: '/oggi' },
    { title: props.linea.nome, href: '#' },
];

// A local copy: each tap changes it at once, and the server saves in the background.
const fermate = ref<FermataOggi[]>(JSON.parse(JSON.stringify(props.fermate)));

// One indicator for everything that is being saved.
const inCorso = ref(0);
const errore = ref<string | null>(null);

const inizio = () => {
    inCorso.value++;
    errore.value = null;
};

const fine = (messaggio: string | null) => {
    inCorso.value = Math.max(0, inCorso.value - 1);
    if (messaggio !== null) {
        errore.value = messaggio;
    }
};

const stato = computed(() => {
    if (errore.value) {
        return { testo: errore.value, classe: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200' };
    }
    if (inCorso.value > 0) {
        return { testo: 'Salvataggio…', classe: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200' };
    }

    return { testo: 'Tutto salvato', classe: 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200' };
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

            <!-- Always visible while scrolling: the chaperone must know whether a tap was saved. -->
            <div role="status" aria-live="polite" :class="['sticky top-2 z-10 rounded-md border px-4 py-2 text-sm font-medium', stato.classe]">
                {{ stato.testo }}
            </div>

            <p v-if="!modifica_aperta && arrivo" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                Sono passati più di {{ minuti_modifica }} minuti dall'arrivo ({{ arrivo }}): le presenze non si possono più modificare.
            </p>

            <template v-for="(fermata, indice) in fermate" :key="fermata.id">
                <SchedaFermataOggi
                    v-if="fermata.mia"
                    :fermata="fermata"
                    :inizio="indice === primaMia"
                    :modifica-aperta="modifica_aperta"
                    @inizio="inizio"
                    @fine="fine"
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
