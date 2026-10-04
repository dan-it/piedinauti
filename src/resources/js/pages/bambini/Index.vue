<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Paginazione from '@/components/Paginazione.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';

interface Bambino {
    id: number;
    nome: string;
    cognome: string;
}

interface Pagina {
    data: Bambino[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{ bambini: Pagina; ricerca: string }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bambini', href: '/bambini' }];

// Search as you type, waiting a moment after the last key.
const testo = ref(props.ricerca);
let attesa: ReturnType<typeof setTimeout> | undefined;

const cerca = () => {
    clearTimeout(attesa);
    attesa = setTimeout(() => {
        router.get(route('bambini.index'), testo.value ? { q: testo.value } : {}, { preserveState: true, replace: true });
    }, 300);
};

onBeforeUnmount(() => clearTimeout(attesa));

const elimina = (bambino: Bambino) => {
    if (confirm(`Eliminare ${[bambino.nome, bambino.cognome].filter(Boolean).join(' ')}? Verranno eliminate anche le sue presenze. L'operazione non si può annullare.`)) {
        router.delete(route('bambini.destroy', bambino.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Bambini" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <Heading title="Bambini" description="I bambini della tua città. Basta il nome: il cognome è facoltativo." />
                <Button as-child>
                    <Link :href="route('bambini.create')"><Plus class="h-4 w-4" /> Aggiungi un bambino</Link>
                </Button>
            </div>

            <Input v-model="testo" type="search" placeholder="Cerca per nome o cognome" class="max-w-sm" aria-label="Cerca un bambino" @input="cerca" />

            <div v-if="bambini.data.length === 0" class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                {{ ricerca ? 'Nessun bambino corrisponde alla ricerca.' : 'Non c\'è ancora nessun bambino. Aggiungine uno per iniziare.' }}
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Cognome</th>
                            <th class="px-4 py-3 font-medium">Nome</th>
                            <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="bambino in bambini.data" :key="bambino.id" class="border-t">
                            <td class="px-4 py-3 font-medium">{{ bambino.cognome || '—' }}</td>
                            <td class="px-4 py-3">{{ bambino.nome }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="route('bambini.edit', bambino.id)"><Pencil class="h-4 w-4" /> Modifica</Link>
                                </Button>
                                <Button variant="ghost" size="sm" class="text-red-600" @click="elimina(bambino)">
                                    <Trash2 class="h-4 w-4" /> Elimina
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Paginazione
                :pagina-corrente="bambini.current_page"
                :ultima-pagina="bambini.last_page"
                :totale="bambini.total"
                :precedente="bambini.prev_page_url"
                :successiva="bambini.next_page_url"
            />
        </div>
    </AppLayout>
</template>
