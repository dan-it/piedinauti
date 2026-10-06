<script setup lang="ts">
import type { BambinoOggi } from '@/components/SchedaFermataOggi.vue';
import StatoPresenza from '@/components/StatoPresenza.vue';

// A stop just before the chaperone's own, which the line lets them look at: who is there and what
// state each child is in. Read only: the chaperone marks only from their starting stop onwards.
defineProps<{
    fermata: { id: number; nome: string; orario: string; bambini: BambinoOggi[] };
}>();
</script>

<template>
    <section class="space-y-3 rounded-xl border border-dashed p-4">
        <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="text-lg font-semibold text-muted-foreground">
                <span class="tabular-nums">{{ fermata.orario }}</span> · {{ fermata.nome }}
            </h2>
            <p class="text-xs text-muted-foreground">Prima della tua partenza · sola lettura</p>
        </header>

        <p v-if="fermata.bambini.length === 0" class="text-sm text-muted-foreground">Nessun bambino assegnato a questa fermata.</p>

        <ul v-else class="divide-y rounded-lg border text-sm">
            <li v-for="bambino in fermata.bambini" :key="bambino.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                <span class="font-medium">{{ bambino.nome }}</span>
                <span v-if="bambino.temporaneo" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-950 dark:text-sky-200">Solo oggi</span>
                <span class="ml-auto"><StatoPresenza :stato="bambino.presente" /></span>
            </li>
        </ul>
    </section>
</template>
