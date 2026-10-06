<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { type FiltriReport, parametri } from '@/lib/report';
import { Link } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';

// The report sections as tabs, keeping the filters, and the CSV download.
defineProps<{ attiva: 'linee' | 'bambini'; filtri: FiltriReport }>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button :variant="attiva === 'linee' ? 'default' : 'outline'" as-child>
            <Link :href="route('report.index', parametri(filtri))">Per linea e giorno</Link>
        </Button>
        <Button :variant="attiva === 'bambini' ? 'default' : 'outline'" as-child>
            <Link :href="route('report.bambini', parametri(filtri))">Per bambino</Link>
        </Button>
        <Button variant="ghost" class="ml-auto" as-child>
            <a :href="route('report.esporta', parametri(filtri))"><Download class="h-4 w-4" /> Scarica CSV del periodo</a>
        </Button>
    </div>
</template>
