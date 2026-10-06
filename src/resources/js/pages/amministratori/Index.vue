<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Send, Trash2, UserMinus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Amministratore {
    id: number;
    nome: string;
    cognome: string;
    email: string;
    ruoli: string[];
    // Roles other than administrator ones: managed by the city's administrators.
    altri_ruoli: string[];
    citta: string | null;
    citta_id: number | null;
    attivo: boolean;
    sei_tu: boolean;
    puo_eliminare: boolean;
    puo_revocare: boolean;
}

const props = defineProps<{
    amministratori: Amministratore[];
    citte: { id: number; nome: string }[];
    citte_senza_amministratori: { id: number; nome: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Amministratori', href: '/amministratori' }];

// Search and city filter work on the list already on the page.
const ricerca = ref('');
const cittaScelta = ref<'tutte' | 'globali' | number>('tutte');

const normalizza = (testo: string) => testo.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const visibili = computed(() => {
    const parole = normalizza(ricerca.value.trim()).split(/\s+/).filter(Boolean);

    return props.amministratori.filter((persona) => {
        if (cittaScelta.value === 'globali' && persona.citta_id !== null) {
            return false;
        }
        if (typeof cittaScelta.value === 'number' && persona.citta_id !== cittaScelta.value) {
            return false;
        }

        // Every word typed must appear in the name, email or city.
        const testo = normalizza(`${persona.nome} ${persona.cognome} ${persona.email} ${persona.citta ?? ''}`);

        return parole.every((parola) => testo.includes(parola));
    });
});

const reinvia = (persona: Amministratore) => {
    router.post(route('amministratori.reinvia', persona.id), {}, { preserveScroll: true });
};

const revoca = (persona: Amministratore) => {
    const rimasti = persona.altri_ruoli.map((ruolo) => ruolo.toLowerCase()).join(' e ');

    if (confirm(`Togliere a ${persona.nome} ${persona.cognome} il ruolo di amministratore di ${persona.citta}? Resta: ${rimasti}.`)) {
        router.post(route('amministratori.revoca', persona.id), {}, { preserveScroll: true });
    }
};

const elimina = (persona: Amministratore) => {
    if (confirm(`Eliminare ${persona.nome} ${persona.cognome}? L'operazione non si può annullare.`)) {
        router.delete(route('amministratori.destroy', persona.id), { preserveScroll: true });
    }
};

const selectClass =
    'flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head title="Amministratori" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <Heading title="Amministratori" description="Chi amministra le città e chi amministra l'intero servizio, anche se ha altri ruoli." />
                <Button as-child>
                    <Link :href="route('amministratori.create')"><Plus class="h-4 w-4" /> Invita un amministratore</Link>
                </Button>
            </div>

            <!-- Cities that nobody administers: they cannot manage themselves. -->
            <div
                v-if="citte_senza_amministratori.length > 0"
                role="alert"
                class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
            >
                <p class="font-medium">
                    {{ citte_senza_amministratori.length === 1 ? 'Una città è senza amministratori' : `${citte_senza_amministratori.length} città sono senza amministratori` }}:
                    {{ citte_senza_amministratori.map((citta) => citta.nome).join(', ') }}
                </p>
                <p>Nessuno può gestirle finché non inviti un amministratore di città.</p>
            </div>

            <!-- Search and city filter -->
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid gap-1">
                    <label for="ricerca" class="text-sm font-medium">Cerca</label>
                    <Input id="ricerca" v-model="ricerca" type="search" placeholder="Nome, email o città" class="w-64" />
                </div>
                <div class="grid gap-1">
                    <label for="citta" class="text-sm font-medium">Città</label>
                    <select id="citta" v-model="cittaScelta" :class="selectClass">
                        <option value="tutte">Tutte le città</option>
                        <option value="globali">Solo amministratori globali</option>
                        <option v-for="citta in citte" :key="citta.id" :value="citta.id">{{ citta.nome }}</option>
                    </select>
                </div>
                <p class="pb-2 text-sm text-muted-foreground">{{ visibili.length }} di {{ amministratori.length }}</p>
            </div>

            <p v-if="visibili.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                Nessun amministratore corrisponde alla ricerca.
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Persona</th>
                            <th class="px-4 py-3 font-medium">Ruoli</th>
                            <th class="px-4 py-3 font-medium">Città</th>
                            <th class="px-4 py-3 font-medium">Stato</th>
                            <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="persona in visibili" :key="persona.id" class="border-t align-top">
                            <td class="px-4 py-3">
                                <div class="font-medium">
                                    {{ persona.nome }} {{ persona.cognome }}
                                    <span v-if="persona.sei_tu" class="font-normal text-muted-foreground">(tu)</span>
                                </div>
                                <div class="text-muted-foreground">{{ persona.email }}</div>
                            </td>
                            <td class="px-4 py-3">
                                {{ persona.ruoli.join(', ') }}
                                <p v-if="persona.altri_ruoli.length > 0" class="mt-1 text-xs text-muted-foreground">
                                    {{ persona.altri_ruoli.join(', ') }}: gestiti dall'amministratore della città
                                </p>
                            </td>
                            <td class="px-4 py-3">{{ persona.citta ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span v-if="persona.attivo" class="text-green-700 dark:text-green-400">Attivo</span>
                                <span v-else class="text-amber-700 dark:text-amber-400">Invito inviato</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <Button v-if="!persona.attivo" variant="ghost" size="sm" @click="reinvia(persona)">
                                    <Send class="h-4 w-4" /> Reinvia
                                </Button>
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="route('amministratori.edit', persona.id)"><Pencil class="h-4 w-4" /> Modifica</Link>
                                </Button>
                                <Button v-if="persona.puo_revocare" variant="ghost" size="sm" @click="revoca(persona)">
                                    <UserMinus class="h-4 w-4" /> Revoca ruolo
                                </Button>
                                <Button v-if="persona.puo_eliminare" variant="ghost" size="sm" class="text-red-600" @click="elimina(persona)">
                                    <Trash2 class="h-4 w-4" /> Elimina
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-sm text-muted-foreground">
                Responsabili e accompagnatori li gestisce l'amministratore della loro città, dalla voce «Persone». Chi ha anche quei ruoli non si elimina da qui: puoi togliergli
                solo il ruolo di amministratore.
            </p>
        </div>
    </AppLayout>
</template>
