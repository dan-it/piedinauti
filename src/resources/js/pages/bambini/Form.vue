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
    // True for "Save and add another": the server sends us back to an empty form.
    continua: false as boolean,
});

// Saves the child. With `continua` the form is emptied and the cursor goes back to the first field.
const salva = (continua: boolean) => {
    form.continua = continua;

    if (props.bambino) {
        form.put(route('bambini.update', props.bambino.id));

        return;
    }

    form.post(route('bambini.store'), {
        preserveScroll: true,
        onSuccess: () => {
            if (continua) {
                form.reset();
                form.clearErrors();
                document.getElementById('nome')?.focus();
            }
        },
    });
};

// Pressing Enter submits the form: when adding, that means "Save and add another".
const invio = () => salva(!props.bambino);
</script>

<template>
    <Head :title="bambino ? 'Modifica bambino' : 'Aggiungi un bambino'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-xl flex-col gap-6 p-4">
            <Heading :title="bambino ? 'Modifica bambino' : 'Aggiungi un bambino'" />

            <form class="space-y-6" @submit.prevent="invio">
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

                <div class="flex flex-wrap items-center gap-3">
                    <!-- The only submit button when adding: it is what Enter presses. -->
                    <Button v-if="!bambino" type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Salva e aggiungi un altro
                    </Button>
                    <Button v-if="!bambino" type="button" variant="outline" :disabled="form.processing" @click="salva(false)">Salva</Button>

                    <Button v-else type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Salva
                    </Button>

                    <Button variant="ghost" as-child><Link :href="route('bambini.index')">Annulla</Link></Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
