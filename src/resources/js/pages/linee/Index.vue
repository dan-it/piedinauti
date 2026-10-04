<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Copy, Pencil, Plus } from 'lucide-vue-next';

interface Linea {
    id: number;
    nome: string;
    fermate: number;
    primo_orario: string | null;
    ultimo_orario: string | null;
    responsabili: string[];
}

defineProps<{ linee: Linea[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Linee', href: '/linee' }];
</script>

<template>
    <Head title="Linee" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <Heading title="Linee" description="Andata e ritorno sono linee separate, ciascuna con le proprie fermate." />
                <Button as-child>
                    <Link :href="route('linee.create')"><Plus class="h-4 w-4" /> Nuova linea</Link>
                </Button>
            </div>

            <div v-if="linee.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                Non c'è ancora nessuna linea. Creane una per iniziare.
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Linea</th>
                            <th class="px-4 py-3 font-medium">Fermate</th>
                            <th class="px-4 py-3 font-medium">Responsabili</th>
                            <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="linea in linee" :key="linea.id" class="border-t">
                            <td class="px-4 py-3 font-medium">{{ linea.nome }}</td>
                            <td class="px-4 py-3">
                                {{ linea.fermate }}
                                <span v-if="linea.primo_orario" class="text-muted-foreground"> · {{ linea.primo_orario }} → {{ linea.ultimo_orario }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="linea.responsabili.length">{{ linea.responsabili.join(', ') }}</span>
                                <span v-else class="text-amber-700 dark:text-amber-400">Nessuno</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="route('linee.duplica.form', linea.id)"><Copy class="h-4 w-4" /> Duplica</Link>
                                </Button>
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="route('linee.edit', linea.id)"><Pencil class="h-4 w-4" /> Gestisci</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
