<script setup lang="ts">
import { giornoBreve } from '@/lib/report';
import { computed } from 'vue';

// Presences (green) and absences (red) day by day. The same numbers are in the table below:
// the chart never carries information on its own.
const props = defineProps<{ giorni: { data: string; presenti: number; assenti: number }[] }>();

const massimo = computed(() => Math.max(1, ...props.giorni.map((giorno) => giorno.presenti + giorno.assenti)));
const altezza = (valore: number) => `${(valore / massimo.value) * 100}%`;
const descrizione = computed(() => `Grafico a barre: ${props.giorni.length} giorni, al massimo ${massimo.value} bambini segnati in un giorno.`);
</script>

<template>
    <div class="space-y-2">
        <div class="overflow-x-auto pb-1">
            <div class="flex h-40 items-end gap-1" role="img" :aria-label="descrizione">
                <div
                    v-for="giorno in giorni"
                    :key="giorno.data"
                    class="flex h-full w-5 shrink-0 flex-col justify-end"
                    :title="`${giornoBreve(giorno.data)}: ${giorno.presenti} presenti, ${giorno.assenti} assenti`"
                >
                    <div class="bg-red-500" :style="{ height: altezza(giorno.assenti) }" />
                    <div class="bg-green-600" :style="{ height: altezza(giorno.presenti) }" />
                </div>
            </div>
        </div>
        <p class="flex gap-4 text-xs text-muted-foreground">
            <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 bg-green-600" /> Presenti</span>
            <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 bg-red-500" /> Assenti</span>
        </p>
    </div>
</template>
