<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type RuoloCodice, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Home', href: '/dashboard' }];

const etichette: Record<RuoloCodice, string> = {
    admin_globale: 'Amministratore globale',
    admin_citta: 'Amministratore di città',
    responsabile: 'Responsabile',
    accompagnatore: 'Accompagnatore',
};

const page = usePage<SharedData>();
const utente = computed(() => page.props.auth.user);
const ruoli = computed(() => utente.value.ruoli.map((ruolo) => etichette[ruolo]).join(', '));
const eGlobale = computed(() => utente.value.ruoli.includes('admin_globale'));
const eAdminCitta = computed(() => utente.value.ruoli.includes('admin_citta'));
const eResponsabile = computed(() => utente.value.ruoli.includes('responsabile'));
const eAccompagnatore = computed(() => utente.value.ruoli.includes('accompagnatore'));
</script>

<template>
    <Head title="Home" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <Heading :title="`Ciao ${utente.nome}`" :description="[ruoli, utente.citta_nome].filter(Boolean).join(' · ')" />

            <div v-if="eGlobale" class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Città</h2>
                    <p class="mb-4 text-sm text-muted-foreground">Crea le città in cui opera il piedibus.</p>
                    <Button as-child><Link :href="route('citta.index')">Gestisci le città</Link></Button>
                </div>
                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Amministratori</h2>
                    <p class="mb-4 text-sm text-muted-foreground">Invita gli amministratori di ogni città.</p>
                    <Button as-child><Link :href="route('amministratori.index')">Gestisci gli amministratori</Link></Button>
                </div>
            </div>

            <div v-if="eAdminCitta" class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Persone</h2>
                    <p class="mb-4 text-sm text-muted-foreground">Invita responsabili e accompagnatori e gestisci i loro ruoli.</p>
                    <Button as-child><Link :href="route('persone.index')">Gestisci le persone</Link></Button>
                </div>
                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Bambini</h2>
                    <p class="mb-4 text-sm text-muted-foreground">Registra i bambini che partecipano al piedibus.</p>
                    <Button as-child><Link :href="route('bambini.index')">Gestisci i bambini</Link></Button>
                </div>
                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Linee</h2>
                    <p class="mb-4 text-sm text-muted-foreground">Crea le linee, le loro fermate con gli orari e scegli i responsabili.</p>
                    <Button as-child><Link :href="route('linee.index')">Gestisci le linee</Link></Button>
                </div>
            </div>

            <div v-if="eResponsabile || eAdminCitta" class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Presenze</h2>
                    <p class="mb-4 text-sm text-muted-foreground">Chi c'è e chi manca a ogni fermata, giorno per giorno.</p>
                    <Button as-child><Link :href="route('presenze.index')">Guarda le presenze</Link></Button>
                </div>
                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Assegnazioni</h2>
                    <p class="mb-4 text-sm text-muted-foreground">Scegli gli accompagnatori e i bambini di ogni fermata.</p>
                    <Button as-child><Link :href="route('assegnazioni.index')">Gestisci le assegnazioni</Link></Button>
                </div>
            </div>

            <p v-if="utente.ruoli.length === 0" class="text-sm text-muted-foreground">Al tuo account non è ancora stato assegnato un ruolo: chiedi a un amministratore.</p>

            <div v-if="eAccompagnatore" class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border p-4">
                    <h2 class="font-medium">Oggi</h2>
                    <p class="mb-4 text-sm text-muted-foreground">La tua linea di oggi: segna chi è presente a ogni fermata.</p>
                    <Button as-child><Link :href="route('oggi.index')">Apri la schermata di oggi</Link></Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
