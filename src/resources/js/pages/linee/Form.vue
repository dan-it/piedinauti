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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Linee', href: '/linee' },
    { title: 'Nuova linea', href: '#' },
];

const form = useForm({ nome: '' });

const submit = () => form.post(route('linee.store'));
</script>

<template>
    <Head title="Nuova linea" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-xl flex-col gap-6 p-4">
            <Heading title="Nuova linea" description="Dopo aver creato la linea potrai aggiungere le fermate e scegliere i responsabili." />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="nome">Nome della linea</Label>
                    <Input id="nome" v-model="form.nome" required autofocus autocomplete="off" placeholder="Per esempio Linea Verde - Andata" />
                    <InputError :message="form.errors.nome" />
                </div>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Crea la linea
                    </Button>
                    <Button variant="ghost" as-child><Link :href="route('linee.index')">Annulla</Link></Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
