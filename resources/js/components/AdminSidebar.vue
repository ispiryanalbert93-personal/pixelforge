<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { type NavItem, type SharedData } from '@/types';
import { BarChart3, ShieldCheck } from 'lucide-vue-next';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem
} from '@/components/ui/sidebar';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';

const page = usePage<SharedData>();
const appName = computed(() => page.props.name);

const adminNavItems: NavItem[] = [
    {
        title: 'Usage Report',
        href: '/admin/reports',
        icon: BarChart3,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('admin.reports')">
                            <div
                                class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                            >
                                <ShieldCheck class="size-4" />
                            </div>
                            <div class="ml-1 grid flex-1 text-left text-sm">
                                <span class="mb-0.5 truncate font-semibold leading-none">{{ appName }}</span>
                                <span class="truncate text-[11px] text-muted-foreground">Operator Portal</span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="adminNavItems" label="Operator" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
