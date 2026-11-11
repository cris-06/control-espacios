<script setup lang="ts">
import { CircleCheck, CircleX, Clock, TriangleAlert } from '@lucide/vue';
import type { Component } from 'vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { formatearFechaHora } from '@/lib/reservas';
import type { CasillaAutorizacion } from '@/types';

// Casillas de firma del Formato 10. Son de solo lectura: las genera el flujo de la
// reserva a partir de su historial (ReservaController::autorizaciones), nunca se escriben.
defineProps<{
    casillas: CasillaAutorizacion[];
}>();

const estilos: Record<
    CasillaAutorizacion['situacion'],
    { icono: Component; clases: string }
> = {
    completada: { icono: CircleCheck, clases: 'text-green-700' },
    pendiente: { icono: Clock, clases: 'text-muted-foreground' },
    devuelta: { icono: TriangleAlert, clases: 'text-orange-700' },
    denegada: { icono: CircleX, clases: 'text-red-700' },
};
</script>

<template>
    <TarjetaSeccion
        titulo="Autorizaciones"
        descripcion="Las genera el flujo del sistema; no se capturan a mano."
    >
        <dl
            class="grid divide-y divide-border text-sm md:grid-cols-3 md:divide-x md:divide-y-0"
        >
            <div
                v-for="casilla in casillas"
                :key="casilla.clave"
                class="grid content-start gap-1 p-4 md:p-6"
            >
                <dt class="text-xs font-medium text-muted-foreground uppercase">
                    {{ casilla.titulo }}
                </dt>
                <dd class="grid gap-1">
                    <span
                        class="flex items-center gap-1.5 font-medium"
                        :class="estilos[casilla.situacion].clases"
                    >
                        <component
                            :is="estilos[casilla.situacion].icono"
                            class="size-4 shrink-0"
                        />
                        {{ casilla.etiqueta }}
                    </span>
                    <span v-if="casilla.nombre">
                        {{ casilla.nombre }}
                        <span v-if="casilla.rol" class="text-muted-foreground"
                            >· {{ casilla.rol }}</span
                        >
                    </span>
                    <time
                        v-if="casilla.fecha"
                        :datetime="casilla.fecha"
                        class="text-xs text-muted-foreground"
                        >{{ formatearFechaHora(casilla.fecha) }}</time
                    >
                </dd>
            </div>
        </dl>
    </TarjetaSeccion>
</template>
