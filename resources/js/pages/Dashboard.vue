<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TablaReservas from '@/components/TablaReservas.vue';
import TarjetaIndicador from '@/components/TarjetaIndicador.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as solicitudes } from '@/routes/solicitudes';
import type { Indicador, Reserva } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Panel',
                href: dashboard(),
            },
        ],
    },
});

defineProps<{
    indicadores: Indicador[];
    pendientes: Reserva[];
    proximas: Reserva[];
}>();

const page = usePage();
const esDocente = computed(() => page.props.demo?.rol === 'docente');
</script>

<template>
    <Head title="Panel" />

    <PaginaBase
        titulo="Panel"
        descripcion="Resumen de tus reservaciones y pendientes."
    >
        <div
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
            aria-label="Indicadores"
        >
            <TarjetaIndicador
                v-for="indicador in indicadores"
                :key="indicador.titulo"
                :titulo="indicador.titulo"
                :valor="indicador.valor"
                :descripcion="indicador.descripcion"
            />
        </div>

        <TarjetaSeccion
            titulo="Por atender"
            :descripcion="
                esDocente
                    ? 'Solicitudes que requieren tu acción.'
                    : 'Solicitudes que esperan tu revisión.'
            "
        >
            <template #acciones>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="solicitudes()">Ver solicitudes</Link>
                </Button>
            </template>
            <TablaReservas
                :reservas="pendientes"
                :mostrar-docente="!esDocente"
                vacio="No tienes solicitudes por atender."
            />
        </TarjetaSeccion>

        <TarjetaSeccion
            titulo="Próximas reservaciones"
            descripcion="Las siguientes cinco reservaciones autorizadas."
        >
            <TablaReservas
                :reservas="proximas"
                :mostrar-docente="!esDocente"
                vacio="No hay reservaciones próximas."
            />
        </TarjetaSeccion>
    </PaginaBase>
</template>
