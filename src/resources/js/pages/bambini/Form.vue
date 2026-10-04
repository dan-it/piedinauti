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

// The same form adds a child (bambino = null) or edits an existing one.
const props = defineProps<{ bambino: { id: number; nome: string; cognome: string } | null }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Bambini', href: '/bambini' },
    { title: props.bambino ? 'Modifica' : 'Nuovo bambino', href: '#' },
];

const form = useForm({
    nome: props.bambino?.nome ?? '',
    cognome: props.bambino?.cognome ?? '',
});

const submit = () => {
    if (props.bambino) {
        form.put(route('bambini.update', props.bambino.id));
    } else {
        form.post(route('bambini.store'));
    }
};
</script>

<template>
    <Head :title="bambino ? 'Modifica bambino' : 'Aggiungi un bambino'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-xl flex-col gap-6 p-4">
            <Heading :title="bambino ? 'Modifica bambino' : 'Aggiungi un bambino'" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="nome">Nome</Label>
                    <Input id="nome" v-model="form.nome" required autofocus autocomplete="off" />
                    <InputError :message="form.errors.nome" />
                </div>

                <div class="grid gap-2">
                    <Label for="cognome">Cognome <span class="font-normal text-muted-foreground">(facoltativo)</span></Label>
                    <Input id="cognome" v-model="form.cognome" autocomplete="off" />
                    <InputError :message="form.errors.cognome" />
                </div>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Salva
                    </Button>
                    <Button variant="ghost" as-child><Link :href="route('bambini.index')">Annulla</Link></Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
