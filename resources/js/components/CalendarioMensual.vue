<script setup lang="ts">
import { ChevronLeft, ChevronRight, Lock } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { CalendarioMes } from '@/types';

const props = defineProps<{
    calendario: CalendarioMes;
    // Día que muestra la agenda de abajo (AAAA-MM-DD).
    seleccionada: string | null;
}>();

const emit = defineEmits<{
    seleccionar: [fecha: string];
    // Primer día del mes al que se quiere ir (AAAA-MM-DD).
    cambiarMes: [fecha: string];
}>();

const AUTORIZADA = 5;
const MAXIMO_POR_DIA = 3;
const diasSemana = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

const dosDigitos = (n: number) => String(n).padStart(2, '0');
const aTexto = (d: Date) =>
    `${d.getFullYear()}-${dosDigitos(d.getMonth() + 1)}-${dosDigitos(d.getDate())}`;

const anio = computed(() => Number(props.calendario.mes.slice(0, 4)));
const mes = computed(() => Number(props.calendario.mes.slice(5, 7)) - 1);
const hoy = aTexto(new Date());

const titulo = computed(() =>
    new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric' }).format(
        new Date(anio.value, mes.value, 1),
    ),
);

// Semanas de lunes a domingo; los días de otros meses quedan vacíos.
const celdas = computed(() => {
    const primero = new Date(anio.value, mes.value, 1);
    const huecos = (primero.getDay() + 6) % 7;
    const totalDias = new Date(anio.value, mes.value + 1, 0).getDate();

    return [
        ...Array.from({ length: huecos }, () => null),
        ...Array.from({ length: totalDias }, (_, i) => {
            const fecha = aTexto(new Date(anio.value, mes.value, i + 1));

            return {
                fecha,
                dia: i + 1,
                bloques: props.calendario.dias[fecha] ?? [],
                bloqueado: fecha < props.calendario.fecha_minima,
            };
        }),
    ];
});

const irAMes = (desplazamiento: number) =>
    emit(
        'cambiarMes',
        aTexto(new Date(anio.value, mes.value + desplazamiento, 1)),
    );
</script>

<template>
    <div>
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3"
        >
            <div class="flex items-center gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Mes anterior"
                    @click="irAMes(-1)"
                >
                    <ChevronLeft />
                </Button>
                <h3
                    class="min-w-36 text-center font-semibold capitalize sm:min-w-40"
                >
                    {{ titulo }}
                </h3>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Mes siguiente"
                    @click="irAMes(1)"
                >
                    <ChevronRight />
                </Button>
            </div>
            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Lock class="size-3.5" aria-hidden="true" />
                Días bloqueados: no cumplen los
                {{ calendario.dias_anticipacion }} días de anticipación para
                solicitar.
            </p>
        </div>

        <div>
            <div class="grid grid-cols-7">
                <div
                    v-for="nombre in diasSemana"
                    :key="nombre"
                    class="border-b border-border bg-secondary px-0.5 py-1.5 text-center text-[11px] font-semibold tracking-wide text-secondary-foreground uppercase sm:px-2 sm:text-xs"
                >
                    {{ nombre }}
                </div>
                <template
                    v-for="(celda, i) in celdas"
                    :key="celda?.fecha ?? `hueco-${i}`"
                >
                    <div
                        v-if="celda === null"
                        class="min-h-14 border-r border-b border-border bg-muted/30 sm:min-h-24"
                    />
                    <button
                        v-else
                        type="button"
                        class="flex min-h-14 min-w-0 flex-col gap-1 border-r border-b border-border p-1 text-left transition-colors hover:bg-accent focus-visible:relative focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:min-h-24 sm:p-1.5"
                        :class="[
                            celda.bloqueado
                                ? 'bg-muted/60 text-muted-foreground'
                                : 'bg-card',
                            celda.fecha === seleccionada
                                ? 'ring-2 ring-rojo ring-inset'
                                : '',
                        ]"
                        :aria-label="`${celda.dia}: ${celda.bloques.length} reservas${celda.bloqueado ? ', bloqueado para solicitar' : ''}`"
                        :aria-pressed="celda.fecha === seleccionada"
                        @click="emit('seleccionar', celda.fecha)"
                    >
                        <span class="flex items-center justify-between">
                            <span
                                class="flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums"
                                :class="
                                    celda.fecha === hoy
                                        ? 'bg-rojo text-rojo-foreground'
                                        : ''
                                "
                            >
                                {{ celda.dia }}
                            </span>
                            <Lock
                                v-if="celda.bloqueado"
                                class="hidden size-3 opacity-60 sm:block"
                                aria-hidden="true"
                            />
                        </span>
                        <span
                            v-for="bloque in celda.bloques.slice(
                                0,
                                MAXIMO_POR_DIA,
                            )"
                            :key="bloque.id"
                            class="hidden truncate rounded px-1 py-0.5 text-[11px] leading-tight text-white sm:block"
                            :class="
                                bloque.estado_id === AUTORIZADA
                                    ? ''
                                    : 'opacity-60'
                            "
                            :style="{ backgroundColor: bloque.color }"
                            :title="`${bloque.hora_inicio}–${bloque.hora_fin} · ${bloque.espacio} · ${bloque.actividad}`"
                        >
                            {{ bloque.hora_inicio }} {{ bloque.espacio }}
                        </span>
                        <span
                            v-if="celda.bloques.length"
                            class="flex flex-wrap gap-0.5 sm:hidden"
                            aria-hidden="true"
                        >
                            <span
                                v-for="bloque in celda.bloques.slice(0, 6)"
                                :key="bloque.id"
                                class="size-2 rounded-full"
                                :class="
                                    bloque.estado_id === AUTORIZADA
                                        ? ''
                                        : 'opacity-50'
                                "
                                :style="{ backgroundColor: bloque.color }"
                            />
                        </span>
                        <span
                            v-if="celda.bloques.length > MAXIMO_POR_DIA"
                            class="hidden text-[11px] font-medium text-muted-foreground sm:block"
                        >
                            +{{ celda.bloques.length - MAXIMO_POR_DIA }} más
                        </span>
                    </button>
                </template>
            </div>
        </div>
        <p
            class="flex flex-wrap gap-x-4 gap-y-1 border-t border-border px-4 py-2 text-xs text-muted-foreground"
        >
            <span>Color sólido: autorizada.</span>
            <span>Color tenue: solicitud en revisión.</span>
            <span>Da clic en un día para ver su agenda.</span>
        </p>
    </div>
</template>
