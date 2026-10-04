<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { LoaderCircle, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

interface Persona {
    id: number;
    nome: string;
}

interface Accompagnatore extends Persona {
    // Name of the stop where this person already starts on the line, if it is another one.
    inizia_a: string | null;
}

interface Precedente {
    nome: string;
    da: string;
}

interface Risultato {
    id: number;
    nome: string;
    altra_fermata: string | null;
}

const props = defineProps<{
    linea: { id: number; nome: string };
    fermata: { id: number; nome: string; orario: string };
    accompagnatori: Accompagnatore[];
    assegnati: number[];
    precedenti: Precedente[];
    bambini: Persona[];
    ricerca: string;
    risultati: Risultato[];
}>();

// Only city administrators can invite people; managers are told to ask them.
const eAdminCitta = computed(() => usePage<SharedData>().props.auth.user.ruoli.includes('admin_citta'));

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assegnazioni', href: '/assegnazioni' },
    { title: props.linea.nome, href: route('assegnazioni.linea', props.linea.id) },
    { title: props.fermata.nome, href: '#' },
];

// Chaperones: a short list, chosen with checkboxes. Choosing nobody is allowed.
const formAccompagnatori = useForm({ accompagnatori: [...props.assegnati] });
const salvaAccompagnatori = () =>
    formAccompagnatori.put(route('assegnazioni.accompagnatori', props.fermata.id), { preserveScroll: true });

// Children: assigned ones are listed (possibly none); new ones are found by searching.
const testo = ref(props.ricerca);
let attesa: ReturnType<typeof setTimeout> | undefined;

const cerca = () => {
    clearTimeout(attesa);
    attesa = setTimeout(() => {
        router.get(
            route('assegnazioni.fermata', props.fermata.id),
            testo.value ? { q: testo.value } : {},
            { preserveState: true, preserveScroll: true, replace: true, only: ['risultati', 'ricerca'] },
        );
    }, 300);
};

onBeforeUnmount(() => clearTimeout(attesa));

const aggiungi = (bambino: Risultato) => {
    router.post(route('assegnazioni.bambini.aggiungi', props.fermata.id), { bambino_id: bambino.id }, { preserveScroll: true });
};

const rimuovi = (bambino: Persona) => {
    router.delete(route('assegnazioni.bambini.rimuovi', [props.fermata.id, bambino.id]), { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Fermata ${fermata.nome}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-3xl flex-col gap-10 p-4">
            <Heading :title="fermata.nome" :description="`${linea.nome} · ore ${fermata.orario}`" />

            <section class="space-y-4">
                <HeadingSmall
                    title="Accompagnatori che iniziano da questa fermata"
                    description="Un accompagnatore si assegna solo alla fermata da cui inizia: da lì resta con il gruppo fino all'ultima fermata."
                />

                <div v-if="precedenti.length > 0" class="rounded-lg border bg-muted/40 p-3 text-sm">
                    <p class="mb-1 font-medium">Già con il gruppo da una fermata precedente</p>
                    <ul class="space-y-0.5 text-muted-foreground">
                        <li v-for="persona in precedenti" :key="persona.nome">{{ persona.nome }} <span>(da «{{ persona.da }}»)</span></li>
                    </ul>
                </div>

                <p v-if="accompagnatori.length === 0" class="text-sm text-muted-foreground">
                    Nella tua città non ci sono ancora accompagnatori.
                    <Link v-if="eAdminCitta" :href="route('persone.create')" class="underline">Invita una persona</Link>
                    <span v-else>Chiedi all'amministratore della città di invitarne.</span>
                </p>

                <form v-else class="space-y-4" @submit.prevent="salvaAccompagnatori">
                    <label v-for="persona in accompagnatori" :key="persona.id" class="flex items-center gap-3 text-sm">
                        <input v-model="formAccompagnatori.accompagnatori" type="checkbox" :value="persona.id" />
                        <span>
                            {{ persona.nome }}
                            <span v-if="persona.inizia_a" class="text-amber-700 dark:text-amber-400"> · ora inizia da «{{ persona.inizia_a }}»: scegliendolo qui viene spostato</span>
                        </span>
                    </label>
                    <InputError :message="formAccompagnatori.errors.accompagnatori" />
                    <Button type="submit" :disabled="formAccompagnatori.processing">
                        <LoaderCircle v-if="formAccompagnatori.processing" class="h-4 w-4 animate-spin" />
                        Salva gli accompagnatori
                    </Button>
                </form>
            </section>

            <section class="space-y-4">
                <HeadingSmall title="Bambini" description="Un bambino ha una sola fermata per linea. Una fermata può anche non avere bambini." />

                <p v-if="bambini.length === 0" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    Nessun bambino assegnato a questa fermata.
                </p>

                <ul v-else class="divide-y rounded-lg border text-sm">
                    <li v-for="bambino in bambini" :key="bambino.id" class="flex items-center justify-between gap-3 px-4 py-2">
                        <span>{{ bambino.nome }}</span>
                        <Button variant="ghost" size="sm" class="text-red-600" @click="rimuovi(bambino)">
                            <Trash2 class="h-4 w-4" /> Togli
                        </Button>
                    </li>
                </ul>

                <div class="space-y-3">
                    <Input v-model="testo" type="search" placeholder="Cerca un bambino da aggiungere (almeno 2 lettere)" aria-label="Cerca un bambino" @input="cerca" />

                    <p v-if="ricerca.length >= 2 && risultati.length === 0" class="text-sm text-muted-foreground">
                        Nessun bambino trovato (o sono già tutti su questa fermata).
                    </p>

                    <ul v-if="risultati.length > 0" class="divide-y rounded-lg border text-sm">
                        <li v-for="bambino in risultati" :key="bambino.id" class="flex items-center justify-between gap-3 px-4 py-2">
                            <span>
                                {{ bambino.nome }}
                                <span v-if="bambino.altra_fermata" class="text-amber-700 dark:text-amber-400"> · ora alla fermata «{{ bambino.altra_fermata }}»</span>
                            </span>
                            <Button variant="outline" size="sm" @click="aggiungi(bambino)">
                                <Plus class="h-4 w-4" /> {{ bambino.altra_fermata ? 'Sposta qui' : 'Aggiungi' }}
                            </Button>
                        </li>
                    </ul>
                </div>
            </section>

            <Button variant="ghost" as-child class="self-start">
                <Link :href="route('assegnazioni.linea', linea.id)">← Torna alla linea</Link>
            </Button>
        </div>
    </AppLayout>
</template>
