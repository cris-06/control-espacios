<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Ban,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleX,
    Clock,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, reactive } from 'vue';
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
}>();

const AUTORIZADA = 5;
const HORA_INICIAL = 7;
const HORA_FINAL = 21;
const horas = Array.from(
    { length: HORA_FINAL - HORA_INICIAL },
    (_, indice) => HORA_INICIAL + indice,
);
const inicioJornada = HORA_INICIAL * 60;
const duracionJornada = (HORA_FINAL - HORA_INICIAL) * 60;
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

const cambiarDia = (dias: number) => {
    if (filtros.fecha) {
        filtros.fecha = sumarDias(filtros.fecha, dias);
        consultar();
    }
};

// Reparte en carriles las reservas que se empalman para que ninguna tape a otra.
const repartir = (bloques: Reserva[]) => {
    const finPorCarril: number[] = [];

    return bloques.map((bloque) => {
        const inicio = aMinutos(bloque.hora_inicio);
        const fin = aMinutos(bloque.hora_fin);
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

        <template v-if="!hayErrores">
            <TarjetaSeccion titulo="Agenda del día">
                <div class="overflow-x-auto">
                    <div class="min-w-[56rem]">
                        <div
                            class="flex border-b border-border bg-secondary text-xs font-semibold text-secondary-foreground"
                        >
                            <div
                                class="w-52 shrink-0 px-4 py-2 tracking-wide uppercase"
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
                            <div class="w-52 shrink-0 px-4 py-2 text-sm">
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
                                class="relative flex-1"
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
