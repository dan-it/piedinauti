<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/vue3';

// Previous / next controls for a Laravel paginator, in Italian.
defineProps<{
    paginaCorrente: number;
    ultimaPagina: number;
    totale: number;
    precedente: string | null;
    successiva: string | null;
}>();
</script>

<template>
    <div v-if="ultimaPagina > 1" class="flex items-center justify-between gap-4 text-sm">
        <span class="text-muted-foreground">Pagina {{ paginaCorrente }} di {{ ultimaPagina }} · {{ totale }} in tutto</span>
        <div class="flex gap-2">
            <Button v-if="precedente" variant="outline" size="sm" as-child>
                <Link :href="precedente" preserve-scroll>Precedente</Link>
            </Button>
            <Button v-if="successiva" variant="outline" size="sm" as-child>
                <Link :href="successiva" preserve-scroll>Successiva</Link>
            </Button>
        </div>
    </div>
</template>
