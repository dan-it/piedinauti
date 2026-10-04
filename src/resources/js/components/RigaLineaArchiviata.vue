<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/vue3';
import { ArchiveRestore } from 'lucide-vue-next';

// One archived line with its own restore form: the name can be changed first, in case an
// active line of the same city has taken it in the meantime.
const props = defineProps<{
    linea: { id: number; nome: string; fermate: number; archiviata_il: string };
}>();

const form = useForm({ nome: props.linea.nome });

const ripristina = () => {
    if (confirm(`Ripristinare la linea «${form.nome}»? Tornerà visibile all'amministratore della città.`)) {
        form.post(route('linee.ripristina', props.linea.id), { preserveScroll: true });
    }
};
</script>

<template>
    <tr class="border-t align-top">
        <td class="px-4 py-3">
            <Input v-model="form.nome" required autocomplete="off" :aria-label="`Nome della linea ${linea.nome}`" class="min-w-56" />
            <InputError :message="form.errors.nome" class="mt-1" />
        </td>
        <td class="px-4 py-3 text-right">{{ linea.fermate }}</td>
        <td class="whitespace-nowrap px-4 py-3">{{ linea.archiviata_il }}</td>
        <td class="whitespace-nowrap px-4 py-3 text-right">
            <Button variant="ghost" size="sm" :disabled="form.processing" @click="ripristina">
                <ArchiveRestore class="h-4 w-4" /> Ripristina
            </Button>
        </td>
    </tr>
</template>
