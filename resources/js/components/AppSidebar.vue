<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    ChartColumn,
    ClipboardList,
    LayoutGrid,
} from '@lucide/vue';
import { useMounted } from '@vueuse/core';
import { computed, onBeforeUnmount, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import NavMain from '@/components/NavMain.vue';
import SelectorRolDemo from '@/components/SelectorRolDemo.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as disponibilidad } from '@/routes/disponibilidad';
import { index as reportes } from '@/routes/reportes';
import { index as solicitudes } from '@/routes/solicitudes';
import type { NavItem } from '@/types';

const page = usePage();
const esDocente = computed(() => page.props.demo?.rol === 'docente');

// Ocultar Reportes solo ordena el menú: quien protege la ruta es ReporteController.
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Panel',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Disponibilidad',
        href: disponibilidad(),
        icon: CalendarDays,
    },
    {
        title: esDocente.value ? 'Mis solicitudes' : 'Solicitudes',
        href: solicitudes(),
        icon: ClipboardList,
        conteo: page.props.demo?.notificaciones.length,
    },
    ...(esDocente.value
        ? []
        : [{ title: 'Reportes', href: reportes(), icon: ChartColumn }]),
]);

const { open, isMobile } = useSidebar();

// El servidor no conoce el ancho de pantalla: el cajón móvil solo existe tras montar la página.
const montado = useMounted();

const flotando = ref(false);
const extendido = computed(() => open.value || flotando.value);

let temporizador: ReturnType<typeof setTimeout> | undefined;

// Con el menú reducido, pasar el ratón lo despliega encima del contenido sin moverlo.
const desplegar = () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        flotando.value = true;
    }, 150);
};

const recoger = () => {
    clearTimeout(temporizador);
    flotando.value = false;
};

const recogerAlSalirDelFoco = (evento: FocusEvent) => {
    const menu = evento.currentTarget as HTMLElement;

    if (!menu.contains(evento.relatedTarget as Node | null)) {
        recoger();
    }
};

onBeforeUnmount(() => clearTimeout(temporizador));
</script>

<template>
    <Sidebar v-if="montado && isMobile">
        <SidebarHeader
            class="h-16 flex-row items-center gap-3 border-b border-sidebar-border px-4"
        >
            <AppLogoIcon class="size-10 object-contain" />
            <span class="truncate text-sm font-semibold">
                {{ page.props.name }}
            </span>
        </SidebarHeader>

        <SidebarContent class="pt-3">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border">
            <SelectorRolDemo />
        </SidebarFooter>
    </Sidebar>

    <div
        class="hidden shrink-0 transition-[width] duration-200 ease-linear md:block print:hidden"
        :class="open ? 'w-64' : 'w-18'"
    ></div>
    <aside
        class="fixed top-16 bottom-0 left-0 z-10 hidden flex-col overflow-hidden border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-[width,box-shadow] duration-200 ease-linear md:flex print:hidden"
        :class="[
            extendido ? 'w-64' : 'w-18',
            flotando && !open ? 'shadow-xl' : '',
        ]"
        @mouseenter="desplegar"
        @mouseleave="recoger"
        @focusin="desplegar"
        @focusout="recogerAlSalirDelFoco"
    >
        <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto pt-3">
            <NavMain :items="mainNavItems" :compacto="!extendido" />
        </div>
        <div
            v-show="extendido"
            class="w-64 shrink-0 border-t border-sidebar-border"
        >
            <SelectorRolDemo />
        </div>
    </aside>
    <slot />
</template>
