<script setup lang="ts">
import { Check, X } from 'lucide-vue-next';

// One child's state on the day: present, absent, or not marked. Never colour alone:
// each state also has an icon and a word.
defineProps<{ stato: boolean | null }>();
</script>

<template>
    <span
        v-if="stato === true"
        class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-950 dark:text-green-200"
    >
        <Check class="h-3.5 w-3.5" /> Presente
    </span>
    <span
        v-else-if="stato === false"
        class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-200"
    >
        <X class="h-3.5 w-3.5" /> Assente
    </span>
    <span v-else class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200">
        — Non segnato
    </span>
</template>
