<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Shows the one-off message set by the server after an action (success or error).
const page = usePage<SharedData>();
const flash = computed(() => page.props.flash);
</script>

<template>
    <div v-if="flash?.status" role="status" class="mx-4 mt-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
        {{ flash.status }}
    </div>
    <div v-if="flash?.avviso" role="alert" class="mx-4 mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
        {{ flash.avviso }}
    </div>
    <div v-if="flash?.errore" role="alert" class="mx-4 mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
        {{ flash.errore }}
    </div>
</template>
