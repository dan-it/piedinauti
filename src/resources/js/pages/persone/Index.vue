<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Send, Trash2 } from 'lucide-vue-next';

interface Persona {
    id: number;
    nome: string;
    cognome: string;
    email: string;
    ruoli: string[];
    attivo: boolean;
    sei_tu: boolean;
}

defineProps<{ persone: Persona[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Persone', href: '/persone' }];

const reinvia = (persona: Persona) => {
    router.post(route('persone.reinvia', persona.id), {}, { preserveScroll: true });
};

const elimina = (persona: Persona) => {
    if (confirm(`Eliminare ${persona.nome} ${persona.cognome}? Verrà tolto anche dalle linee e dalle fermate. L'operazione non si può annullare.`)) {
        router.delete(route('persone.destroy', persona.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Persone" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <Heading title="Persone" description="Amministratori, responsabili e accompagnatori della tua città." />
                <Button as-child>
                    <Link :href="route('persone.create')"><Plus class="h-4 w-4" /> Invita una persona</Link>
                </Button>
            </div>

            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Persona</th>
                            <th class="px-4 py-3 font-medium">Ruoli</th>
                            <th class="px-4 py-3 font-medium">Stato</th>
                            <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="persona in persone" :key="persona.id" class="border-t">
                            <td class="px-4 py-3">
                                <div class="font-medium">
                                    {{ persona.nome }} {{ persona.cognome }}
                                    <span v-if="persona.sei_tu" class="font-normal text-muted-foreground">(tu)</span>
                                </div>
                                <div class="text-muted-foreground">{{ persona.email }}</div>
                            </td>
                            <td class="px-4 py-3">{{ persona.ruoli.join(', ') }}</td>
                            <td class="px-4 py-3">
                                <span v-if="persona.attivo" class="text-green-700 dark:text-green-400">Attivo</span>
                                <span v-else class="text-amber-700 dark:text-amber-400">Invito inviato</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <Button v-if="!persona.attivo" variant="ghost" size="sm" @click="reinvia(persona)">
                                    <Send class="h-4 w-4" /> Reinvia
                                </Button>
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="route('persone.edit', persona.id)"><Pencil class="h-4 w-4" /> Modifica</Link>
                                </Button>
                                <Button v-if="!persona.sei_tu" variant="ghost" size="sm" class="text-red-600" @click="elimina(persona)">
                                    <Trash2 class="h-4 w-4" /> Elimina
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
