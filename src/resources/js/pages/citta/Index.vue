<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, Plus } from 'lucide-vue-next';

interface Citta {
    id: number;
    nome: string;
    persone: number;
    bambini: number;
    linee: number;
}

defineProps<{ citta: Citta[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Città', href: '/citta' }];
</script>

<template>
    <Head title="Città" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <Heading title="Città" description="Le città in cui opera il piedibus. I dati di ogni città sono separati dalle altre." />
                <Button as-child>
                    <Link :href="route('citta.create')"><Plus class="h-4 w-4" /> Nuova città</Link>
                </Button>
            </div>

            <div v-if="citta.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                Non c'è ancora nessuna città. Creane una per iniziare.
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Città</th>
                            <th class="px-4 py-3 text-right font-medium">Persone</th>
                            <th class="px-4 py-3 text-right font-medium">Bambini</th>
                            <th class="px-4 py-3 text-right font-medium">Linee</th>
                            <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="riga in citta" :key="riga.id" class="border-t">
                            <td class="px-4 py-3 font-medium">{{ riga.nome }}</td>
                            <td class="px-4 py-3 text-right">{{ riga.persone }}</td>
                            <td class="px-4 py-3 text-right">{{ riga.bambini }}</td>
                            <td class="px-4 py-3 text-right">{{ riga.linee }}</td>
                            <td class="px-4 py-3 text-right">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="route('citta.edit', riga.id)"><Pencil class="h-4 w-4" /> Rinomina</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
