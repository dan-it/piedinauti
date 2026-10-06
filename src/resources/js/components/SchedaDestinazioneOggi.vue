<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { nuovaAzione } from '@/lib/coda';
import { accoda } from '@/lib/rete';
import { Flag } from 'lucide-vue-next';

export interface ArrivoRegistrato {
    ora: string;
    da: string | null;
}

const props = defineProps<{
    lineaId: number;
    nome: string;
    orario: string;
    // The day this screen is about (YYYY-MM-DD).
    giorno: string;
    // False once 30 minutes have passed since the line's arrival: nothing can be changed any more.
    modificaAperta: boolean;
}>();

// The time the line actually arrived, once someone has tapped "arrived".
const arrivo = defineModel<ArrivoRegistrato | null>('arrivo', { required: true });

const ora = (momento: Date) => momento.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });

// Cancels the arrival tapped by mistake. Same rule as every other change: possible while the
// modification window is open (judged at the moment of the tap, so it also works without signal).
const annulla = async () => {
    if (!arrivo.value || !props.modificaAperta) {
        return;
    }

    if (!window.confirm(`Annullare l'arrivo segnato alle ${arrivo.value.ora}?`)) {
        return;
    }

    arrivo.value = null;

    await accoda(
        nuovaAzione({
            // The same key as "arrived": a later action replaces an earlier one still waiting.
            chiave: `a:${props.lineaId}`,
            tipo: 'arrivo',
            url: route('oggi.arrivo.annulla', props.lineaId),
            corpo: { registrata_il: new Date().toISOString() },
            riferimento: { giorno: props.giorno, lineaId: props.lineaId, annullato: true },
        }),
    );
};

// One tap: the time of the tap is what gets saved, even if it reaches the server later.
const arrivati = async () => {
    if (arrivo.value || !props.modificaAperta) {
        return;
    }

    const momento = new Date();
    arrivo.value = { ora: ora(momento), da: null };

    await accoda(
        nuovaAzione({
            chiave: `a:${props.lineaId}`,
            tipo: 'arrivo',
            url: route('oggi.arrivo', props.lineaId),
            corpo: { registrata_il: momento.toISOString() },
            riferimento: { giorno: props.giorno, lineaId: props.lineaId, ora: ora(momento) },
        }),
    );
};
</script>

<template>
    <section class="space-y-4 rounded-xl border-2 border-primary/40 p-4">
        <header class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="text-xl font-semibold">
                <span class="tabular-nums">{{ orario }}</span> · {{ nome }}
                <span class="ml-2 rounded-full bg-primary px-2 py-0.5 align-middle text-xs font-medium text-primary-foreground">Destinazione</span>
            </h2>
        </header>

        <p class="text-sm text-muted-foreground">Qui non sale nessun bambino: segna solo quando siete arrivati.</p>

        <div v-if="arrivo" class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
            <p class="text-lg font-semibold">Arrivati alle {{ arrivo.ora }}</p>
            <p v-if="arrivo.da" class="text-sm">Segnato da {{ arrivo.da }}</p>
            <Button v-if="modificaAperta" type="button" variant="outline" size="sm" class="mt-3 border-green-300 bg-transparent" @click="annulla">
                Annulla l'arrivo
            </Button>
        </div>

        <Button v-else-if="modificaAperta" type="button" size="lg" class="h-16 w-full text-lg" @click="arrivati">
            <Flag class="h-6 w-6" /> Arrivati
        </Button>

        <p v-else class="rounded-lg border border-dashed p-4 text-center text-muted-foreground">Arrivo non segnato: il tempo per segnarlo è scaduto.</p>
    </section>
</template>
