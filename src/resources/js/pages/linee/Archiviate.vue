<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import RigaLineaArchiviata from '@/components/RigaLineaArchiviata.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

interface LineaArchiviata {
    id: number;
    nome: string;
    fermate: number;
    archiviata_il: string;
}

interface Gruppo {
    citta: string | null;
    linee: LineaArchiviata[];
}

defineProps<{ gruppi: Gruppo[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Linee archiviate', href: '/linee-archiviate' }];
</script>

<template>
    <Head title="Linee archiviate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-8 p-4">
            <Heading
                title="Linee archiviate"
                description="Le linee archiviate dagli amministratori di città, divise per città. Solo tu puoi vederle e ripristinarle."
            />

            <div v-if="gruppi.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                Non ci sono linee archiviate.
            </div>

            <section v-for="gruppo in gruppi" :key="gruppo.citta ?? ''" class="space-y-3">
                <h2 class="text-lg font-medium">
                    {{ gruppo.citta }}
                    <span class="text-sm font-normal text-muted-foreground">· {{ gruppo.linee.length }} {{ gruppo.linee.length === 1 ? 'linea' : 'linee' }}</span>
                </h2>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Linea</th>
                                <th class="px-4 py-3 text-right font-medium">Fermate</th>
                                <th class="px-4 py-3 font-medium">Archiviata il</th>
                                <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <RigaLineaArchiviata v-for="linea in gruppo.linee" :key="linea.id" :linea="linea" />
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
