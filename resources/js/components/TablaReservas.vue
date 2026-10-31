<script setup lang="ts">
import { computed } from 'vue';
import EstadoBadge from '@/components/EstadoBadge.vue';
import TablaDatos from '@/components/TablaDatos.vue';
import { formatearFecha } from '@/lib/reservas';
import type { Reserva } from '@/types';

const props = defineProps<{
    reservas: Reserva[];
    vacio: string;
    mostrarDocente?: boolean;
}>();

const columnas = computed(() => [
    { clave: 'folio', titulo: 'Folio' },
    { clave: 'actividad', titulo: 'Actividad' },
    ...(props.mostrarDocente ? [{ clave: 'docente', titulo: 'Docente' }] : []),
    { clave: 'licenciatura', titulo: 'Licenciatura' },
    { clave: 'espacio', titulo: 'Espacio' },
    { clave: 'fecha', titulo: 'Fecha' },
    { clave: 'horario', titulo: 'Horario' },
    { clave: 'estado', titulo: 'Estado' },
]);
</script>

<template>
    <TablaDatos :columnas="columnas" :filas="reservas" :vacio="vacio">
        <template #folio="{ fila }">
            <span class="font-medium whitespace-nowrap tabular-nums">{{
                fila.folio
            }}</span>
        </template>
        <template #fecha="{ fila }">
            <span class="whitespace-nowrap">{{
                formatearFecha(fila.fecha)
            }}</span>
        </template>
        <template #horario="{ fila }">
            <span class="whitespace-nowrap tabular-nums">
                {{ fila.hora_inicio }} – {{ fila.hora_fin }}
            </span>
        </template>
        <template #estado="{ fila }">
            <EstadoBadge :estado-id="fila.estado_id" :estado="fila.estado" />
        </template>
    </TablaDatos>
</template>
