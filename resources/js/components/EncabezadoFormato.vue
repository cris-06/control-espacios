<script setup lang="ts">
import { computed } from 'vue';
import { formatearFecha } from '@/lib/reservas';

// Encabezado institucional del Formato 10 (actividades o eventos). Solo muestra datos
// que fija el servidor: el folio y las fechas automáticas nunca se capturan aquí.
const props = defineProps<{
    // null mientras la reserva no se ha guardado.
    folio: string | null;
    // ISO 8601 (fecha_creacion) o null si aún no se guarda.
    fechaCreacion: string | null;
    // AAAA-MM-DD (fecha_entrega_formato) o null si aún no se envía.
    fechaEntrega: string | null;
    // Estado actual (null en una reserva nueva). Solo un borrador está por enviarse.
    estadoId: number | null;
}>();

// Sin fecha guardada: un borrador (o una reserva nueva) la registrará al enviarse; una
// reserva ya enviada antes de existir esta fecha no tiene una registrada y no se inventa.
const ESTADO_BORRADOR = 1;
const sinEntrega = computed(() =>
    props.estadoId === null || props.estadoId === ESTADO_BORRADOR
        ? 'Se registrará al enviar'
        : 'No registrada',
);

// La fecha de elaboración es la del servidor al crear (la parte AAAA-MM-DD de su ISO local).
const elaboracion = computed(() =>
    props.fechaCreacion
        ? formatearFecha(props.fechaCreacion.slice(0, 10))
        : null,
);
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-border bg-card shadow-sm print:shadow-none"
        aria-label="Encabezado del formato"
    >
        <div class="h-1.5 bg-rojo" aria-hidden="true" />
        <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center md:p-6">
            <img
                src="/images/logo_cuv.png"
                alt="Centro Universitario de Valladolid"
                class="h-14 w-auto shrink-0 self-start object-contain sm:h-16"
            />
            <div class="min-w-0 flex-1">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Centro Universitario de Valladolid
                </p>
                <h2 class="text-lg font-semibold text-rojo">
                    Formato de actividades o eventos
                </h2>
            </div>
        </div>
        <dl
            class="grid border-t border-border text-sm sm:grid-cols-3 sm:divide-x sm:divide-border"
        >
            <div class="px-4 py-3 md:px-6">
                <dt class="text-xs text-muted-foreground uppercase">
                    No. de folio
                </dt>
                <dd v-if="folio" class="font-semibold">{{ folio }}</dd>
                <dd v-else class="text-muted-foreground italic">
                    Se asignará al guardar
                </dd>
            </div>
            <div class="border-t border-border px-4 py-3 sm:border-t-0 md:px-6">
                <dt class="text-xs text-muted-foreground uppercase">
                    Fecha de elaboración
                </dt>
                <dd v-if="elaboracion" class="font-medium">
                    <time :datetime="fechaCreacion ?? undefined">{{
                        elaboracion
                    }}</time>
                </dd>
                <dd v-else class="text-muted-foreground italic">
                    Se registrará al guardar
                </dd>
            </div>
            <div class="border-t border-border px-4 py-3 sm:border-t-0 md:px-6">
                <dt class="text-xs text-muted-foreground uppercase">
                    Fecha de entrega
                </dt>
                <dd v-if="fechaEntrega" class="font-medium">
                    <time :datetime="fechaEntrega">{{
                        formatearFecha(fechaEntrega)
                    }}</time>
                </dd>
                <dd v-else class="text-muted-foreground italic">
                    {{ sinEntrega }}
                </dd>
            </div>
        </dl>
    </section>
</template>
