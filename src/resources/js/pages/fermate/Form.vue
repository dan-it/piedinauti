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
    fermata: { id: number; nome: string; orario: string } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Linee', href: '/linee' },
    { title: props.linea.nome, href: route('linee.edit', props.linea.id) },
    { title: props.fermata ? 'Modifica fermata' : 'Nuova fermata', href: '#' },
];

const form = useForm({
    nome: props.fermata?.nome ?? '',
    orario: props.fermata?.orario ?? '',
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
