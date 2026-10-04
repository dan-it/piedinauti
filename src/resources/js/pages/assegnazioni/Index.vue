<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface Linea {
    id: number;
    nome: string;
    fermate: number;
    bambini: number;
    senza_accompagnatore: number;
}

defineProps<{ linee: Linea[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Assegnazioni', href: '/assegnazioni' }];
</script>

<template>
    <Head title="Assegnazioni" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <Heading title="Assegnazioni" description="Scegli le linee su cui assegnare accompagnatori e bambini alle fermate." />

            <div v-if="linee.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                Non hai ancora nessuna linea. Un amministratore della tua città deve indicarti come responsabile di una linea.
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Linea</th>
                            <th class="px-4 py-3 text-right font-medium">Fermate</th>
                            <th class="px-4 py-3 text-right font-medium">Bambini</th>
                            <th class="px-4 py-3 font-medium">Da completare</th>
                            <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="linea in linee" :key="linea.id" class="border-t">
                            <td class="px-4 py-3 font-medium">{{ linea.nome }}</td>
                            <td class="px-4 py-3 text-right">{{ linea.fermate }}</td>
                            <td class="px-4 py-3 text-right">{{ linea.bambini }}</td>
                            <td class="px-4 py-3">
                                <span v-if="linea.fermate === 0" class="text-muted-foreground">Nessuna fermata</span>
                                <span v-else-if="linea.senza_accompagnatore > 0" class="text-amber-700 dark:text-amber-400">
                                    {{ linea.senza_accompagnatore }} {{ linea.senza_accompagnatore === 1 ? 'fermata senza accompagnatore' : 'fermate senza accompagnatore' }}
                                </span>
                                <span v-else class="text-green-700 dark:text-green-400">Ogni fermata ha un accompagnatore</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="route('assegnazioni.linea', linea.id)">Apri</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
