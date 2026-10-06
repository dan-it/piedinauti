<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface Fermata {
    id: number;
    nome: string;
    orario: string;
    destinazione: boolean;
    accompagnatori: { nome: string; da: string | null }[];
    bambini: string[];
}

const props = defineProps<{
    linea: { id: number; nome: string };
    fermate: Fermata[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assegnazioni', href: '/assegnazioni' },
    { title: props.linea.nome, href: '#' },
];
</script>

<template>
    <Head :title="`Assegnazioni · ${linea.nome}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-3xl flex-col gap-6 p-4">
            <Heading :title="linea.nome" description="Per ogni fermata, chi è con il gruppo e quali bambini salgono. Un accompagnatore compare da dove inizia fino all'ultima fermata; una fermata può anche non avere bambini." />

            <div v-if="fermate.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                Questa linea non ha ancora fermate: le aggiunge l'amministratore della città.
            </div>

            <div v-for="fermata in fermate" :key="fermata.id" class="space-y-3 rounded-lg border p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <span class="font-medium tabular-nums">{{ fermata.orario }}</span>
                        <span class="ml-2 font-medium">{{ fermata.nome }}</span>
                    </div>
                    <span v-if="fermata.destinazione" class="rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground">Destinazione</span>
                    <Button v-else variant="outline" size="sm" as-child>
                        <Link :href="route('assegnazioni.fermata', fermata.id)">Gestisci</Link>
                    </Button>
                </div>

                <p v-if="fermata.destinazione" class="text-sm text-muted-foreground">
                    Qui non sale nessun bambino: gli accompagnatori con il gruppo segnano solo «Arrivati».
                </p>

                <div v-else class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <p class="mb-1 text-muted-foreground">Accompagnatori</p>
                        <p v-if="fermata.accompagnatori.length === 0" class="text-amber-700 dark:text-amber-400">Nessun accompagnatore</p>
                        <ul v-else class="space-y-0.5">
                            <li v-for="persona in fermata.accompagnatori" :key="persona.nome">
                                {{ persona.nome }}
                                <span v-if="persona.da" class="text-muted-foreground">(da «{{ persona.da }}»)</span>
                                <span v-else class="text-muted-foreground">(inizia qui)</span>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <p class="mb-1 text-muted-foreground">Bambini ({{ fermata.bambini.length }})</p>
                        <p v-if="fermata.bambini.length === 0" class="text-muted-foreground">Nessun bambino</p>
                        <p v-else>{{ fermata.bambini.join(', ') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
