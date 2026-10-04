<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Aspetto',
        href: '/settings/appearance',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Aspetto" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Aspetto" description="Scegli il tema chiaro, scuro o quello del tuo dispositivo" />
                <AppearanceTabs />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
