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

// The same form adds a stop to a line (fermata = null) or edits an existing one.
const props = defineProps<{
    linea: { id: number; nome: string };
    fermata: { id: number; nome: string; orario: string; destinazione: boolean } | null;
    // Name of another stop of the line that is already its destination (a line has only one).
    destinazione_esistente: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Linee', href: '/linee' },
    { title: props.linea.nome, href: route('linee.edit', props.linea.id) },
    { title: props.fermata ? 'Modifica fermata' : 'Nuova fermata', href: '#' },
];

const form = useForm({
    nome: props.fermata?.nome ?? '',
    orario: props.fermata?.orario ?? '',
    destinazione: props.fermata?.destinazione ?? false,
});

const submit = () => {
    if (props.fermata) {
        form.put(route('fermate.update', props.fermata.id));
    } else {
        form.post(route('fermate.store', props.linea.id));
    }
};
</script>

<template>
    <Head :title="fermata ? 'Modifica fermata' : 'Nuova fermata'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-xl flex-col gap-6 p-4">
            <Heading :title="fermata ? 'Modifica fermata' : 'Nuova fermata'" :description="`Linea: ${linea.nome}`" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="nome">Nome della fermata</Label>
                    <Input id="nome" v-model="form.nome" required autofocus autocomplete="off" placeholder="Per esempio Parco giochi" />
                    <InputError :message="form.errors.nome" />
                </div>

                <div class="grid gap-2">
                    <Label for="orario">Orario di passaggio</Label>
                    <Input id="orario" v-model="form.orario" type="time" required class="w-40" />
                    <InputError :message="form.errors.orario" />
                    <p class="text-sm text-muted-foreground">Le fermate della linea si riordinano da sole in base all'orario.</p>
                </div>

                <div class="grid gap-2">
                    <label class="flex items-start gap-3 text-sm" :class="destinazione_esistente ? 'opacity-60' : ''">
                        <input v-model="form.destinazione" type="checkbox" class="mt-1" :disabled="destinazione_esistente !== null && !form.destinazione" />
                        <span>
                            <span class="font-medium">È la destinazione (per esempio la scuola)</span>
                            <span class="block text-muted-foreground">
                                Ultima fermata della linea: nessun bambino sale qui, gli accompagnatori segnano solo «Arrivati» e viene salvato l'orario.
                            </span>
                        </span>
                    </label>
                    <p v-if="destinazione_esistente" class="text-sm text-muted-foreground">
                        La destinazione di questa linea è già «{{ destinazione_esistente }}»: ce ne può essere una sola.
                    </p>
                    <InputError :message="form.errors.destinazione" />
                </div>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Salva
                    </Button>
                    <Button variant="ghost" as-child><Link :href="route('linee.edit', linea.id)">Annulla</Link></Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
