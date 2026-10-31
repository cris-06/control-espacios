<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BotonEncabezado from '@/components/BotonEncabezado.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import MenuNotificaciones from '@/components/MenuNotificaciones.vue';
import MenuPerfil from '@/components/MenuPerfil.vue';
import { useSidebar } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { toggleSidebar } = useSidebar();
</script>

<template>
    <header
        class="fixed inset-x-0 top-0 z-20 flex h-16 items-center gap-1 border-b-[3px] border-primary bg-rojo px-3 text-rojo-foreground shadow-sm md:pr-4 md:pl-[1.125rem] print:hidden"
    >
        <BotonEncabezado aria-label="Menú principal" @click="toggleSidebar">
            <Menu class="size-5" />
        </BotonEncabezado>
        <Link
            :href="dashboard()"
            class="ml-1 flex min-w-0 flex-1 items-center gap-3"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-md bg-white p-0.5"
            >
                <AppLogoIcon class="size-full object-contain" />
            </span>
            <span class="grid min-w-0 leading-tight">
                <span class="truncate font-semibold sm:text-lg">
                    Sistema de reservas
                </span>
                <span class="hidden truncate text-xs sm:block">
                    Centro Universitario de Valladolid
                </span>
            </span>
        </Link>
        <MenuNotificaciones />
        <MenuPerfil />
    </header>
    <div
        v-if="breadcrumbs.length > 1"
        class="flex h-10 shrink-0 items-center border-b border-sidebar-border/70 bg-background px-4 print:hidden"
    >
        <Breadcrumbs :breadcrumbs="breadcrumbs" />
    </div>
</template>
