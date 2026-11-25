<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Lock, Plus } from '@lucide/vue';
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { aMinutos, formatearFechaLarga } from '@/lib/reservas';
import { create } from '@/routes/reservas';
import type { BloqueCalendario, CalendarioMes } from '@/types';

// Tabla de horarios de un día del calendario: qué reservas tiene cada espacio por hora y, en
// las horas libres de un día que cumple la anticipación, un acceso para crear la reserva con el
// espacio, la fecha y el horario ya elegidos. Solo orienta: la disponibilidad real se confirma
// al guardar y al autorizar.
const props = defineProps<{
    calendario: CalendarioMes;
    fecha: string | null;
    // Solo docentes y coordinadores crean reservas (ReservaPolicy::create).
    puedeCrear: boolean;
}>();

const abierto = defineModel<boolean>('abierto', { required: true });

const AUTORIZADA = 5;
const HORA_INICIAL = 7;
const HORA_FINAL = 21;

const dosDigitos = (n: number) => String(n).padStart(2, '0');
const hhmm = (minutos: number) =>
    `${dosDigitos(Math.floor(minutos / 60))}:${dosDigitos(minutos % 60)}`;

const bloques = computed<BloqueCalendario[]>(() =>
    props.fecha ? (props.calendario.dias[props.fecha] ?? []) : [],
);

// De 7:00 a 21:00, ampliado si alguna reserva del día empieza antes o termina después.
const horas = computed(() => {
    const desde = Math.min(
        HORA_INICIAL,
        ...bloques.value.map((b) => Math.floor(aMinutos(b.hora_inicio) / 60)),
    );
    const hasta = Math.max(
        HORA_FINAL,
        ...bloques.value.map((b) => Math.ceil(aMinutos(b.hora_fin) / 60)),
    );

    return Array.from(
        { length: Math.max(0, hasta - desde) },
        (_, i) => desde + i,
    );
});

const bloqueado = computed(
    () => !props.fecha || props.fecha < props.calendario.fecha_minima,
);
const reservable = computed(() => props.puedeCrear && !bloqueado.value);

type Celda =
    | { tipo: 'libre' }
    | { tipo: 'fuera' }
    | { tipo: 'ocupado' | 'revision'; bloque: BloqueCalendario };

// Situación de un espacio en la hora [h, h+1): una reserva autorizada la ocupa; una en revisión
// solo avisa (todavía no ocupa el espacio, pero podría autorizarse).
const celda = (
    espacio: CalendarioMes['espacios'][number],
    hora: number,
): Celda => {
    if (!espacio.disponible) {
        return { tipo: 'fuera' };
    }

    const inicio = hora * 60;
    const fin = inicio + 60;
    const delEspacio = bloques.value.filter(
        (b) =>
            b.espacio_id === espacio.id &&
            aMinutos(b.hora_inicio) < fin &&
            inicio < aMinutos(b.hora_fin),
    );
    const autorizado = delEspacio.find((b) => b.estado_id === AUTORIZADA);

    if (autorizado) {
        return { tipo: 'ocupado', bloque: autorizado };
    }

    return delEspacio.length
        ? { tipo: 'revision', bloque: delEspacio[0] }
        : { tipo: 'libre' };
};

const enlaceReservar = (espacioId: number, hora: number) =>
    create.url({
        query: {
            espacio_id: espacioId,
            fecha: props.fecha ?? '',
            hora_inicio: hhmm(hora * 60),
            hora_fin: hhmm((hora + 1) * 60),
        },
    });

const reservasDelDia = computed(() =>
    [...bloques.value].sort((a, b) =>
        a.hora_inicio.localeCompare(b.hora_inicio),
    ),
);
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent
            class="flex max-h-[90vh] flex-col gap-3 p-4 sm:max-w-3xl sm:p-6"
        >
            <DialogHeader>
                <DialogTitle class="first-letter:uppercase">
                    {{ fecha ? formatearFechaLarga(fecha) : '' }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="reservable">
                        Elige un horario libre para crear la reserva con ese
                        espacio, fecha y hora.
                    </template>
                    <span
                        v-else-if="bloqueado"
                        class="flex items-start gap-1.5"
                    >
                        <Lock
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Este día no cumple los
                        {{ calendario.dias_anticipacion }} días de anticipación:
                        solo puedes consultar sus reservas.
                    </span>
                    <template v-else>
                        Consulta las reservas y los horarios libres de cada
                        espacio.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="min-h-0 overflow-y-auto">
                <section class="mb-3">
                    <h3 class="mb-1 text-sm font-semibold text-rojo">
                        Reservas del día
                    </h3>
                    <p
                        v-if="reservasDelDia.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No hay reservas este día.
                    </p>
                    <ul v-else class="grid gap-1 text-sm">
                        <li
                            v-for="b in reservasDelDia"
                            :key="b.id"
                            class="flex flex-wrap items-center gap-x-2"
                        >
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: b.color }"
                                aria-hidden="true"
                            />
                            <span class="font-medium tabular-nums"
                                >{{ b.hora_inicio }} – {{ b.hora_fin }}</span
                            >
                            <span>{{ b.espacio }}</span>
                            <span class="text-muted-foreground"
                                >· {{ b.actividad }}</span
                            >
                            <span
                                class="text-xs"
                                :class="
                                    b.estado_id === AUTORIZADA
                                        ? 'text-green-700'
                                        : 'text-amber-700'
                                "
                                >{{
                                    b.estado_id === AUTORIZADA
                                        ? 'Autorizada'
                                        : 'En revisión'
                                }}</span
                            >
                        </li>
                    </ul>
                </section>

                <div
                    class="overflow-x-auto rounded-md border border-border"
                    tabindex="0"
                    aria-label="Horarios por espacio"
                >
                    <table class="w-full border-collapse text-xs sm:text-sm">
                        <thead class="bg-secondary text-secondary-foreground">
                            <tr>
                                <th
                                    scope="col"
                                    class="sticky left-0 z-10 bg-secondary px-2 py-2 text-left font-semibold"
                                >
                                    Hora
                                </th>
                                <th
                                    v-for="e in calendario.espacios"
                                    :key="e.id"
                                    scope="col"
                                    class="min-w-28 px-2 py-2 text-left font-semibold"
                                >
                                    <span class="flex items-center gap-1.5">
                                        <span
                                            class="size-2.5 shrink-0 rounded-full"
                                            :style="{
                                                backgroundColor: e.color,
                                            }"
                                            aria-hidden="true"
                                        />
                                        {{ e.nombre }}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="h in horas"
                                :key="h"
                                class="border-t border-border"
                            >
                                <th
                                    scope="row"
                                    class="sticky left-0 z-10 bg-card px-2 py-1 text-left font-medium whitespace-nowrap tabular-nums"
                                >
                                    {{ hhmm(h * 60) }} –
                                    {{ hhmm((h + 1) * 60) }}
                                </th>
                                <td
                                    v-for="e in calendario.espacios"
                                    :key="e.id"
                                    class="px-1.5 py-1"
                                >
                                    <template
                                        v-for="c in [celda(e, h)]"
                                        :key="c.tipo"
                                    >
                                        <span
                                            v-if="c.tipo === 'fuera'"
                                            class="text-muted-foreground"
                                            >Fuera de servicio</span
                                        >
                                        <span
                                            v-else-if="c.tipo !== 'libre'"
                                            class="block truncate rounded px-1.5 py-0.5"
                                            :class="
                                                c.tipo === 'ocupado'
                                                    ? 'bg-red-50 text-red-800'
                                                    : 'bg-amber-50 text-amber-800'
                                            "
                                            :title="`${c.bloque.hora_inicio} – ${c.bloque.hora_fin} · ${c.bloque.actividad}`"
                                        >
                                            {{
                                                c.tipo === 'ocupado'
                                                    ? 'Ocupado'
                                                    : 'En revisión'
                                            }}
                                        </span>
                                        <Link
                                            v-else-if="reservable"
                                            :href="enlaceReservar(e.id, h)"
                                            class="inline-flex items-center gap-1 rounded border border-input px-1.5 py-0.5 text-rojo hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :aria-label="`Reservar ${e.nombre} de ${hhmm(h * 60)} a ${hhmm((h + 1) * 60)}`"
                                        >
                                            <Plus
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            Reservar
                                        </Link>
                                        <span v-else class="text-green-700"
                                            >Libre</span
                                        >
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
