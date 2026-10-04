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

const props = defineProps<{
    linea: { id: number; nome: string; fermate: number };
    nome_proposto: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Linee', href: '/linee' },
    { title: props.linea.nome, href: route('linee.edit', props.linea.id) },
    { title: 'Duplica', href: '#' },
];

const form = useForm({ nome: props.nome_proposto });

const submit = () => form.post(route('linee.duplica', props.linea.id));
</script>

<template>
    <Head title="Duplica la linea" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-xl flex-col gap-6 p-4">
            <Heading
                title="Duplica la linea"
                :description="`Crea una nuova linea con le stesse ${linea.fermate} fermate (nomi e orari) di «${linea.nome}». Utile, per esempio, per preparare il ritorno.`"
            />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="nome">Nome della nuova linea</Label>
                    <Input id="nome" v-model="form.nome" required autofocus autocomplete="off" />
                    <InputError :message="form.errors.nome" />
                    <p class="text-sm text-muted-foreground">
                        Responsabili, accompagnatori e bambini non vengono copiati: potrai sceglierli per la nuova linea. Gli orari si cambiano dopo la copia.
                    </p>
                </div>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Duplica la linea
                    </Button>
                    <Button variant="ghost" as-child><Link :href="route('linee.edit', linea.id)">Annulla</Link></Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
