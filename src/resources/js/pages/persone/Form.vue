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

// The same form invites a new person (persona = null) or edits an existing one.
const props = defineProps<{
    persona: { id: number; nome: string; cognome: string; email: string; attivo: boolean; ruoli: string[]; sei_tu: boolean } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Persone', href: '/persone' },
    { title: props.persona ? 'Modifica' : 'Nuova persona', href: '#' },
];

// A person can hold several roles at once.
const ruoliDisponibili = [
    { valore: 'responsabile', etichetta: 'Responsabile', aiuto: 'Assegna accompagnatori e bambini alle fermate delle sue linee.' },
    { valore: 'accompagnatore', etichetta: 'Accompagnatore', aiuto: 'Segna le presenze alla sua fermata.' },
    { valore: 'admin_citta', etichetta: 'Amministratore di città', aiuto: 'Gestisce tutto nella città: persone, bambini, linee e fermate.' },
];

const form = useForm({
    nome: props.persona?.nome ?? '',
    cognome: props.persona?.cognome ?? '',
    email: props.persona?.email ?? '',
    ruoli: [...(props.persona?.ruoli ?? [])],
});

const submit = () => {
    if (props.persona) {
        form.put(route('persone.update', props.persona.id));
    } else {
        form.post(route('persone.store'));
    }
};
</script>

<template>
    <Head :title="persona ? 'Modifica persona' : 'Invita una persona'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-xl flex-col gap-6 p-4">
            <Heading
                :title="persona ? 'Modifica persona' : 'Invita una persona'"
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

                <fieldset class="grid gap-3">
                    <legend class="mb-1 text-sm font-medium leading-none">Ruoli (almeno uno)</legend>
                    <label v-for="ruolo in ruoliDisponibili" :key="ruolo.valore" class="flex items-start gap-3 text-sm">
                        <input v-model="form.ruoli" type="checkbox" :value="ruolo.valore" class="mt-1" />
                        <span>
                            <span class="font-medium">{{ ruolo.etichetta }}</span>
                            <span class="block text-muted-foreground">{{ ruolo.aiuto }}</span>
                        </span>
                    </label>
                    <InputError :message="form.errors.ruoli" />
                    <p v-if="persona" class="text-sm text-muted-foreground">
                        Togliendo il ruolo di responsabile o di accompagnatore, la persona esce anche dalle sue linee e fermate.
                    </p>
                </fieldset>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        {{ persona ? 'Salva' : 'Invia l\'invito' }}
                    </Button>
                    <Button variant="ghost" as-child><Link :href="route('persone.index')">Annulla</Link></Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
