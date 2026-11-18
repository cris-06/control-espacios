<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccionesSolicitud from '@/components/AccionesSolicitud.vue';
import EstadoBadge from '@/components/EstadoBadge.vue';
import TablaDatos from '@/components/TablaDatos.vue';
import { formatearFecha } from '@/lib/reservas';
import { show } from '@/routes/reservas';
import type { ReservaFila } from '@/types';

const props = defineProps<{
    reservas: ReservaFila[];
    vacio: string;
    mostrarDocente?: boolean;
    // Solo para reservas reales: los datos de ejemplo del panel no tienen detalle.
    enlazar?: boolean;
    // Columna ACCIONES al final (solo el listado de solicitudes; las filas traen `acciones`).
    conAcciones?: boolean;
}>();

const columnas = computed(() => [
    { clave: 'folio', titulo: 'Folio' },
    { clave: 'actividad', titulo: 'Actividad' },
    ...(props.mostrarDocente
        ? [{ clave: 'docente', titulo: 'Solicitante' }]
        : []),
    { clave: 'licenciatura', titulo: 'Licenciatura' },
    { clave: 'espacio', titulo: 'Espacio' },
    { clave: 'fecha', titulo: 'Fecha' },
    { clave: 'horario', titulo: 'Horario' },
    { clave: 'estado', titulo: 'Estado' },
    ...(props.conAcciones
        ? [{ clave: 'acciones', titulo: 'Acciones', fija: true }]
        : []),
]);
</script>

<template>
    <TablaDatos :columnas="columnas" :filas="reservas" :vacio="vacio">
        <template #folio="{ fila }">
            <Link
                v-if="enlazar && fila.visible !== false"
                :href="show(fila.reserva_id ?? fila.id)"
                class="font-medium whitespace-nowrap text-rojo tabular-nums underline-offset-4 hover:underline"
                >{{ fila.folio }}</Link
            >
            <span v-else class="font-medium whitespace-nowrap tabular-nums">{{
                fila.folio
            }}</span>
        </template>
        <template #espacio="{ fila }">
            <span v-if="fila.espacio">{{ fila.espacio }}</span>
            <span v-else class="text-muted-foreground">Sin espacio</span>
        </template>
        <template #fecha="{ fila }">
            <span v-if="fila.fecha" class="whitespace-nowrap">{{
                formatearFecha(fila.fecha)
            }}</span>
            <span v-else class="text-muted-foreground">—</span>
        </template>
        <template #horario="{ fila }">
            <span
                v-if="fila.hora_inicio"
                class="whitespace-nowrap tabular-nums"
            >
                {{ fila.hora_inicio }} – {{ fila.hora_fin }}
            </span>
            <span v-else class="text-muted-foreground">—</span>
        </template>
        <template #estado="{ fila }">
            <EstadoBadge :estado-id="fila.estado_id" :estado="fila.estado" />
        </template>
        <template #acciones="{ fila }">
            <AccionesSolicitud
                v-if="fila.acciones"
                :reserva-id="fila.reserva_id ?? fila.id"
                :folio="fila.folio"
                :acciones="fila.acciones"
            />
        </template>
    </TablaDatos>
</template>
