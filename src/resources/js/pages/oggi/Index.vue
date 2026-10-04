<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface LineaOggi {
    id: number;
    nome: string;
    prima_orario: string;
    inizio: { nome: string; orario: string };
}

defineProps<{ linee: LineaOggi[]; data: string }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Oggi', href: '/oggi' }];
</script>

<template>
    <Head title="Oggi" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-2xl flex-col gap-6 p-4">
            <Heading :title="data.charAt(0).toUpperCase() + data.slice(1)" description="Scegli la linea che accompagni." />

            <div v-if="linee.length === 0" class="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
                Non hai ancora nessuna fermata assegnata. Chiedi al responsabile della linea di assegnartene una.
            </div>

            <Button
                v-for="linea in linee"
                :key="linea.id"
                as-child
                variant="outline"
                class="h-auto justify-start whitespace-normal p-4 text-left"
            >
                <Link :href="route('oggi.linea', linea.id)">
                    <span class="block">
                        <span class="block text-lg font-semibold">{{ linea.nome }}</span>
                        <span class="block text-sm font-normal text-muted-foreground">
                            Parti da {{ linea.inizio.orario }} {{ linea.inizio.nome }} e resti con il gruppo fino all'ultima fermata
                        </span>
                    </span>
                </Link>
            </Button>
        </div>
    </AppLayout>
</template>
