<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

// The same form creates a city (citta = null) or renames an existing one.
const props = defineProps<{ citta: { id: number; nome: string } | null }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Città', href: '/citta' },
    { title: props.citta ? 'Rinomina' : 'Nuova città', href: '#' },
];

const form = useForm({ nome: props.citta?.nome ?? '' });

const submit = () => {
    if (props.citta) {
        form.put(route('citta.update', props.citta.id));
    } else {
        form.post(route('citta.store'));
    }
};
</script>

<template>
    <Head :title="citta ? 'Rinomina città' : 'Nuova città'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-xl flex-col gap-6 p-4">
            <Heading :title="citta ? 'Rinomina città' : 'Nuova città'" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="nome">Nome della città</Label>
                    <Input id="nome" v-model="form.nome" required autofocus autocomplete="off" placeholder="Per esempio Milano" />
                    <InputError :message="form.errors.nome" />
                </div>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Salva
                    </Button>
                    <Button variant="ghost" as-child><Link :href="route('citta.index')">Annulla</Link></Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
