<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { scorciatoie } from '@/lib/report';
import { ref } from 'vue';

// The filter bar of the reports: period, line, and (for global administrators) city.
const props = defineProps<{
    da: string;
    a: string;
    lineaScelta: number | null;
    linee: { id: number; nome: string }[];
    citte: { id: number; nome: string }[];
    cittaScelta: number | null;
    // Hide the line choice where it makes no sense.
    senzaLinea?: boolean;
}>();

const emit = defineEmits<{ applica: [filtri: { da: string; a: string; linea: number | null; citta: number | null }] }>();

const da = ref(props.da);
const a = ref(props.a);
const linea = ref<number | null>(props.lineaScelta);
const citta = ref<number | null>(props.cittaScelta);

const applica = () => {
    if (da.value && a.value) {
        emit('applica', { da: da.value, a: a.value, linea: linea.value, citta: citta.value });
    }
};

// Changing city starts over: the lines are another city's.
const cambiaCitta = () => {
    linea.value = null;
    applica();
};

const scegliScorciatoia = (scorciatoia: { da: string; a: string }) => {
    da.value = scorciatoia.da;
    a.value = scorciatoia.a;
    applica();
};

const oggi = new Date().toLocaleDateString('sv-SE');
const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <form class="space-y-3 rounded-xl border p-4" @submit.prevent="applica">
        <div class="flex flex-wrap items-end gap-3">
            <div v-if="citte.length > 0" class="grid gap-1">
                <label for="report-citta" class="text-sm font-medium">Città</label>
                <select id="report-citta" v-model="citta" :class="selectClass" @change="cambiaCitta">
                    <option v-for="c in citte" :key="c.id" :value="c.id">{{ c.nome }}</option>
                </select>
            </div>

            <div class="grid gap-1">
                <label for="report-da" class="text-sm font-medium">Dal</label>
                <Input id="report-da" v-model="da" type="date" :max="a || oggi" class="w-40" />
            </div>

            <div class="grid gap-1">
                <label for="report-a" class="text-sm font-medium">Al</label>
                <Input id="report-a" v-model="a" type="date" :min="da" :max="oggi" class="w-40" />
            </div>

            <div v-if="!senzaLinea" class="grid gap-1">
                <label for="report-linea" class="text-sm font-medium">Linea</label>
                <select id="report-linea" v-model="linea" :class="selectClass">
                    <option :value="null">Tutte le linee</option>
                    <option v-for="l in linee" :key="l.id" :value="l.id">{{ l.nome }}</option>
                </select>
            </div>

            <Button type="submit">Applica</Button>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button v-for="s in scorciatoie()" :key="s.etichetta" type="button" variant="outline" size="sm" @click="scegliScorciatoia(s)">{{ s.etichetta }}</Button>
        </div>
    </form>
</template>
