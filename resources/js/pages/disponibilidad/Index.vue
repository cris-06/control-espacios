<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Ban,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleX,
    Clock,
    MoveHorizontal,
} from '@lucide/vue';
import { useElementSize, useScroll } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, nextTick, reactive, ref, useTemplateRef, watch } from 'vue';
import CalendarioMensual from '@/components/CalendarioMensual.vue';
import HorarioDia from '@/components/HorarioDia.vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TablaReservas from '@/components/TablaReservas.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    aMinutos,
    formatearFechaLarga,
    sinVacios,
    sumarDias,
} from '@/lib/reservas';
import { index } from '@/routes/disponibilidad';
import type {
    CalendarioMes,
    EspacioDisponibilidad,
    Opcion,
    Reserva,
    Situacion,
} from '@/types';

type Filtros = {
    fecha: string | null;
    espacio_id: string | null;
    hora_inicio: string | null;
    hora_fin: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Disponibilidad',
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    filtros: Filtros;
    errores: Partial<Record<keyof Filtros, string>>;
    opcionesEspacios: Opcion[];
    espacios: EspacioDisponibilidad[];
    calendario: CalendarioMes;
    puedeCrear: boolean;
}>();

const AUTORIZADA = 5;
// La agenda muestra de 7:00 a 21:00 y se amplía si alguna reserva del día empieza antes o
// termina después, para que ningún bloque quede fuera de la cuadrícula.
const HORA_INICIAL = 7;
const HORA_FINAL = 21;
// Ancho mínimo de cada hora (en rem); la columna de espacios mide --columna (w-36 / sm:w-52).
const REM_POR_HORA = 2.75;
const jornada = computed(() => {
    const bloques = props.espacios.flatMap((espacio) => espacio.bloques);
    const desde = Math.min(
        HORA_INICIAL,
        ...bloques.map((b) => Math.floor(aMinutos(b.hora_inicio) / 60)),
    );
    const hasta = Math.max(
        HORA_FINAL,
        ...bloques.map((b) => Math.ceil(aMinutos(b.hora_fin) / 60)),
    );
    const inicio = Math.max(0, desde);
    const fin = Math.min(24, hasta);

    return { inicio, fin, minutos: (fin - inicio) * 60 };
});
const horas = computed(() =>
    Array.from(
        { length: jornada.value.fin - jornada.value.inicio },
        (_, indice) => jornada.value.inicio + indice,
    ),
);
const anchoAgenda = computed(
    () => `calc(var(--columna) + ${horas.value.length * REM_POR_HORA}rem)`,
);
const alturaCarril = 44;

const situaciones: Record<
    Situacion,
    { texto: string; icono: Component; color: string }
> = {
    libre: {
        texto: 'Libre',
        icono: CircleCheck,
        color: 'border-green-200 bg-green-50 text-green-800',
    },
    pendiente: {
        texto: 'Solicitud en revisión',
        icono: Clock,
        color: 'border-amber-200 bg-amber-50 text-amber-800',
    },
    ocupado: {
        texto: 'Ocupado',
        icono: CircleX,
        color: 'border-red-200 bg-red-50 text-red-800',
    },
    fuera_de_servicio: {
        texto: 'Fuera de servicio',
        icono: Ban,
        color: 'border-border bg-muted text-muted-foreground',
    },
};

const filtros = reactive<Filtros>({ ...props.filtros });

const consultar = () => {
    router.get(index.url(), sinVacios(filtros), {
        preserveState: true,
        preserveScroll: true,
    });
};

// Desde el calendario: un día (o el primero de otro mes) pasa a ser la fecha consultada.
const irAFecha = (fecha: string) => {
    filtros.fecha = fecha;
    consultar();
};

// Al tocar un día se abre su tabla de horarios (y la agenda de abajo pasa a ese día).
const diaAbierto = ref<string | null>(null);
const horarioAbierto = ref(false);
const abrirDia = (fecha: string) => {
    diaAbierto.value = fecha;
    horarioAbierto.value = true;
    irAFecha(fecha);
};

const cambiarDia = (dias: number) => {
    if (filtros.fecha) {
        filtros.fecha = sumarDias(filtros.fecha, dias);
        consultar();
    }
};

// Reparte en carriles las reservas que se empalman para que ninguna tape a otra.
const repartir = (bloques: Reserva[]) => {
    const finPorCarril: number[] = [];

    const inicioJornada = jornada.value.inicio * 60;
    const finJornada = jornada.value.fin * 60;
    const duracionJornada = jornada.value.minutos;

    return bloques.map((bloque) => {
        // Recortado a la jornada visible: nunca se dibuja fuera de la cuadrícula.
        const inicio = Math.max(inicioJornada, aMinutos(bloque.hora_inicio));
        const fin = Math.max(
            inicio,
            Math.min(finJornada, aMinutos(bloque.hora_fin)),
        );
        let carril = finPorCarril.findIndex((finCarril) => finCarril <= inicio);

        if (carril === -1) {
            carril = finPorCarril.length;
        }

        finPorCarril[carril] = fin;

        return {
            ...bloque,
            carril,
            izquierda: ((inicio - inicioJornada) / duracionJornada) * 100,
            ancho: ((fin - inicio) / duracionJornada) * 100,
        };
    });
};

const filas = computed(() =>
    props.espacios.map((espacio) => {
        const bloques = repartir(espacio.bloques);

        return {
            ...espacio,
            bloques,
            carriles: Math.max(1, ...bloques.map(({ carril }) => carril + 1)),
        };
    }),
);

// --- Desplazamiento de la agenda: solo se mueve la línea de tiempo (la columna de espacios
// queda fija y el resto de la página no se desplaza). ---
const agenda = useTemplateRef<HTMLElement>('agenda');
const { arrivedState } = useScroll(agenda);
const { width: anchoVisible } = useElementSize(agenda);
// Hay horas fuera de la vista: se muestran las flechas y el aviso. Se mide después de
// dibujar (el ancho total de la línea de tiempo no es reactivo).
const agendaDesborda = ref(false);
watch(
    [anchoVisible, () => horas.value.length],
    async () => {
        await nextTick();
        const el = agenda.value;
        agendaDesborda.value = !!el && el.scrollWidth > el.clientWidth + 1;
    },
    { flush: 'post' },
);
// Ancho de la columna fija de espacios (la tapa al desplazar).
const anchoColumna = () =>
    agenda.value?.querySelector<HTMLElement>('[data-columna-espacio]')
        ?.offsetWidth ?? 0;
const desplazarAgenda = (sentido: 1 | -1) => {
    const el = agenda.value;

    if (el) {
        el.scrollBy({
            left:
                sentido *
                Math.max(120, (el.clientWidth - anchoColumna()) * 0.8),
            behavior: 'smooth',
        });
    }
};

// Al abrir un día, la agenda se coloca en la primera reserva para que se vea sin buscarla.
watch(
    filas,
    async () => {
        await nextTick();
        const el = agenda.value;
        const primera = Math.min(
            ...filas.value.flatMap((f) => f.bloques.map((b) => b.izquierda)),
        );

        if (!el) {
            return;
        }

        if (!Number.isFinite(primera)) {
            el.scrollLeft = 0;

            return;
        }

        const lineaDeTiempo = el.scrollWidth - anchoColumna();
        el.scrollLeft = Math.max(0, (primera / 100) * lineaDeTiempo - 16);
    },
    { immediate: true, flush: 'post' },
);

const reservasDelDia = computed(() =>
    props.espacios.flatMap((espacio) => espacio.bloques),
);

const hayErrores = computed(() => Object.keys(props.errores).length > 0);
</script>

<template>
    <Head title="Disponibilidad" />

    <PaginaBase
        titulo="Disponibilidad"
        :descripcion="
            props.filtros.fecha && !errores.fecha
                ? formatearFechaLarga(props.filtros.fecha)
                : 'Consulta por fecha, espacio y horario.'
        "
    >
        <TarjetaSeccion titulo="Consulta">
            <form
                class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4"
                @submit.prevent="consultar"
            >
                <CampoFormulario
                    id="fecha"
                    etiqueta="Fecha"
                    :error="errores.fecha"
                >
                    <div class="flex gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Día anterior"
                            @click="cambiarDia(-1)"
                        >
                            <ChevronLeft />
                        </Button>
                        <Input
                            id="fecha"
                            v-model="filtros.fecha as string"
                            type="date"
                            :aria-invalid="!!errores.fecha"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Día siguiente"
                            @click="cambiarDia(1)"
                        >
                            <ChevronRight />
                        </Button>
                    </div>
                </CampoFormulario>
                <CampoFormulario
                    id="espacio"
                    etiqueta="Espacio"
                    :error="errores.espacio_id"
                >
                    <CampoSelect
                        id="espacio"
                        v-model="filtros.espacio_id"
                        :opciones="opcionesEspacios"
                        vacio="Todos los espacios"
                    />
                </CampoFormulario>
                <CampoFormulario
                    id="hora_inicio"
                    etiqueta="Hora de inicio"
                    :error="errores.hora_inicio"
                >
                    <Input
                        id="hora_inicio"
                        v-model="filtros.hora_inicio as string"
                        type="time"
                        :aria-invalid="!!errores.hora_inicio"
                    />
                </CampoFormulario>
                <CampoFormulario
                    id="hora_fin"
                    etiqueta="Hora de fin"
                    :error="errores.hora_fin"
                >
                    <Input
                        id="hora_fin"
                        v-model="filtros.hora_fin as string"
                        type="time"
                        :aria-invalid="!!errores.hora_fin"
                    />
                </CampoFormulario>
                <div class="flex justify-end sm:col-span-2 lg:col-span-4">
                    <Button type="submit">Consultar</Button>
                </div>
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Calendario">
            <CalendarioMensual
                :calendario="calendario"
                :seleccionada="hayErrores ? null : props.filtros.fecha"
                @seleccionar="abrirDia"
                @cambiar-mes="irAFecha"
            />
        </TarjetaSeccion>

        <HorarioDia
            v-model:abierto="horarioAbierto"
            :calendario="calendario"
            :fecha="diaAbierto"
            :puede-crear="puedeCrear"
        />

        <template v-if="!hayErrores">
            <TarjetaSeccion titulo="Agenda del día">
                <template v-if="agendaDesborda" #acciones>
                    <div class="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Horas anteriores"
                            :disabled="arrivedState.left"
                            @click="desplazarAgenda(-1)"
                        >
                            <ChevronLeft />
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Horas siguientes"
                            :disabled="arrivedState.right"
                            @click="desplazarAgenda(1)"
                        >
                            <ChevronRight />
                        </Button>
                    </div>
                </template>
                <p
                    v-if="agendaDesborda"
                    class="flex items-center gap-1.5 border-b border-border px-4 py-2 text-xs text-muted-foreground"
                >
                    <MoveHorizontal
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    Desliza la agenda o usa las flechas para ver más horas.
                </p>
                <div
                    ref="agenda"
                    class="[scrollbar-width:thin] [scrollbar-color:var(--color-rojo)_var(--color-muted)] overflow-x-auto overscroll-x-contain pb-1"
                    tabindex="0"
                    aria-label="Agenda del día, desplazable horizontalmente"
                >
                    <div
                        class="[--columna:9rem] sm:[--columna:13rem]"
                        :style="{ minWidth: anchoAgenda }"
                    >
                        <div
                            class="flex border-b border-border bg-secondary text-xs font-semibold text-secondary-foreground"
                        >
                            <div
                                data-columna-espacio
                                class="sticky left-0 z-10 w-36 shrink-0 bg-secondary px-3 py-2 tracking-wide uppercase shadow-[6px_0_8px_-6px_rgb(0_0_0/0.15)] sm:w-52 sm:px-4"
                            >
                                Espacio
                            </div>
                            <div class="relative flex-1">
                                <span
                                    v-for="(hora, indice) in horas"
                                    :key="hora"
                                    class="absolute top-2 pl-1 tabular-nums"
                                    :style="{
                                        left: `${(indice / horas.length) * 100}%`,
                                    }"
                                >
                                    {{ hora }}:00
                                </span>
                            </div>
                        </div>

                        <div
                            v-for="espacio in filas"
                            :key="espacio.id"
                            class="flex border-b border-border last:border-b-0"
                        >
                            <div
                                class="sticky left-0 z-10 w-36 shrink-0 bg-card px-3 py-2 text-sm shadow-[6px_0_8px_-6px_rgb(0_0_0/0.15)] sm:w-52 sm:px-4"
                            >
                                <p class="flex items-center gap-2 font-medium">
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor:
                                                espacio.color_calendario,
                                        }"
                                    ></span>
                                    {{ espacio.nombre }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ espacio.ubicacion }} · Cupo
                                    {{ espacio.capacidad }}
                                </p>
                                <Badge
                                    v-if="espacio.situacion"
                                    variant="outline"
                                    class="mt-1 gap-1 font-medium"
                                    :class="
                                        situaciones[espacio.situacion].color
                                    "
                                >
                                    <component
                                        :is="
                                            situaciones[espacio.situacion].icono
                                        "
                                        class="size-3"
                                    />
                                    {{ situaciones[espacio.situacion].texto }}
                                </Badge>
                            </div>
                            <div
                                class="relative flex-1 overflow-hidden"
                                :class="{
                                    'bg-muted':
                                        espacio.situacion ===
                                        'fuera_de_servicio',
                                }"
                                :style="{
                                    minHeight: `${espacio.carriles * alturaCarril + 8}px`,
                                }"
                            >
                                <span
                                    v-for="(hora, indice) in horas"
                                    :key="hora"
                                    class="absolute inset-y-0 border-l border-border"
                                    :style="{
                                        left: `${(indice / horas.length) * 100}%`,
                                    }"
                                ></span>
                                <div
                                    v-for="bloque in espacio.bloques"
                                    :key="bloque.id"
                                    class="absolute h-10 overflow-hidden rounded-md border border-l-4 px-2 py-0.5 text-xs leading-tight"
                                    :class="{
                                        'border-dashed':
                                            bloque.estado_id !== AUTORIZADA,
                                    }"
                                    :style="{
                                        left: `${bloque.izquierda}%`,
                                        width: `${bloque.ancho}%`,
                                        top: `${bloque.carril * alturaCarril + 4}px`,
                                        borderColor: espacio.color_calendario,
                                        backgroundColor: `color-mix(in srgb, ${espacio.color_calendario} ${bloque.estado_id === AUTORIZADA ? 14 : 5}%, white)`,
                                    }"
                                    :title="`${bloque.hora_inicio} – ${bloque.hora_fin} · ${bloque.actividad} · ${bloque.estado}`"
                                >
                                    <p class="truncate font-medium">
                                        {{ bloque.actividad }}
                                    </p>
                                    <p
                                        class="truncate text-muted-foreground tabular-nums"
                                    >
                                        {{ bloque.hora_inicio }} –
                                        {{ bloque.hora_fin }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <template #pie>
                    <div class="flex flex-wrap gap-x-6 gap-y-1">
                        <span class="flex items-center gap-2">
                            <span
                                class="h-3 w-5 rounded-sm border border-l-4 border-primary bg-accent"
                            ></span>
                            Autorizada
                        </span>
                        <span class="flex items-center gap-2">
                            <span
                                class="h-3 w-5 rounded-sm border border-l-4 border-dashed border-primary"
                            ></span>
                            Solicitud en revisión, todavía no ocupa el espacio
                        </span>
                    </div>
                </template>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Reservaciones del día">
                <TablaReservas
                    :reservas="reservasDelDia"
                    mostrar-docente
                    vacio="No hay reservaciones para esta fecha."
                />
            </TarjetaSeccion>
        </template>
    </PaginaBase>
</template>
