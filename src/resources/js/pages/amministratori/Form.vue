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

// The same form invites a new administrator (persona = null) or edits an existing one.
const props = defineProps<{
    persona: { id: number; nome: string; cognome: string; email: string; attivo: boolean } | null;
    citta: { id: number; nome: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Amministratori', href: '/amministratori' },
    { title: props.persona ? 'Modifica' : 'Nuovo amministratore', href: '#' },
];

const form = useForm({
    nome: props.persona?.nome ?? '',
    cognome: props.persona?.cognome ?? '',
    email: props.persona?.email ?? '',
    tipo: 'citta',
    citta_id: props.citta.length === 1 ? String(props.citta[0].id) : '',
});

const submit = () => {
    if (props.persona) {
        form.transform(({ nome, cognome, email }) => ({ nome, cognome, email })).put(route('amministratori.update', props.persona.id));
    } else {
        form.post(route('amministratori.store'));
    }
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';
</script>

<template>
    <Head :title="persona ? 'Modifica amministratore' : 'Invita un amministratore'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-xl flex-col gap-6 p-4">
            <Heading
                :title="persona ? 'Modifica amministratore' : 'Invita un amministratore'"
                :description="persona ? undefined : 'Riceverà un\'email con il link per scegliere la password.'"
            />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="nome">Nome</Label>
                    <Input id="nome" v-model="form.nome" required autofocus autocomplete="off" />
                    <InputError :message="form.errors.nome" />
                </div>

                <div class="grid gap-2">
                    <Label for="cognome">Cognome</Label>
                    <Input id="cognome" v-model="form.cognome" required autocomplete="off" />
                    <InputError :message="form.errors.cognome" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Indirizzo email</Label>
                    <Input id="email" v-model="form.email" type="email" required autocomplete="off" />
                    <InputError :message="form.errors.email" />
                    <p v-if="persona && !persona.attivo" class="text-sm text-muted-foreground">
                        Se cambi l'indirizzo, l'invito viene inviato di nuovo al nuovo indirizzo.
                    </p>
                </div>

                <template v-if="!persona">
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium leading-none">Tipo di amministratore</legend>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.tipo" type="radio" value="citta" /> Amministratore di una città
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.tipo" type="radio" value="globale" /> Amministratore globale (tutto il servizio)
                        </label>
                        <InputError :message="form.errors.tipo" />
                    </fieldset>

                    <div v-if="form.tipo === 'citta'" class="grid gap-2">
                        <Label for="citta_id">Città</Label>
                        <select id="citta_id" v-model="form.citta_id" :class="selectClass" required>
                            <option value="" disabled>Scegli una città</option>
                            <option v-for="c in citta" :key="c.id" :value="String(c.id)">{{ c.nome }}</option>
                        </select>
                        <InputError :message="form.errors.citta_id" />
                        <p v-if="citta.length === 0" class="text-sm text-muted-foreground">
                            Non ci sono città: <Link :href="route('citta.create')" class="underline">creane una</Link> prima di invitare un amministratore di città.
                        </p>
                    </div>
                </template>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        {{ persona ? 'Salva' : 'Invia l\'invito' }}
                    </Button>
                    <Button variant="ghost" as-child><Link :href="route('amministratori.index')">Annulla</Link></Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
