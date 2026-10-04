<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Archive, Baby, Building2, CalendarCheck, ClipboardCheck, ClipboardList, Footprints, LayoutGrid, ShieldCheck, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// The menu follows the roles of the signed-in person. The server enforces the same
// rules with policies: hiding a link is a convenience, not the protection.
const mainNavItems = computed<NavItem[]>(() => {
    const ruoli = page.props.auth.user.ruoli;
    const voci: NavItem[] = [];

    // The chaperone's morning screen comes first: it is what they open every day.
    if (ruoli.includes('accompagnatore')) {
        voci.push({ title: 'Oggi', href: route('oggi.index'), icon: CalendarCheck });
    }

    voci.push({ title: 'Home', href: route('dashboard'), icon: LayoutGrid });

    if (ruoli.includes('admin_globale')) {
        voci.push({ title: 'Città', href: route('citta.index'), icon: Building2 });
        voci.push({ title: 'Amministratori', href: route('amministratori.index'), icon: ShieldCheck });
        voci.push({ title: 'Linee archiviate', href: route('linee-archiviate.index'), icon: Archive });
    }

    // Managers and city administrators follow the attendance and assign chaperones and children.
    if (ruoli.includes('admin_citta') || ruoli.includes('responsabile')) {
        voci.push({ title: 'Presenze', href: route('presenze.index'), icon: ClipboardCheck });
        voci.push({ title: 'Assegnazioni', href: route('assegnazioni.index'), icon: ClipboardList });
    }

    if (ruoli.includes('admin_citta')) {
        voci.push({ title: 'Persone', href: route('persone.index'), icon: Users });
        voci.push({ title: 'Bambini', href: route('bambini.index'), icon: Baby });
        voci.push({ title: 'Linee', href: route('linee.index'), icon: Footprints });
    }

    return voci;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
