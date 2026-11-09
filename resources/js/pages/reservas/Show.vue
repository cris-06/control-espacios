<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban,
    CheckCheck,
    CircleCheck,
    CircleX,
    Clock,
    FilePlus,
    Pencil,
    Send,
    TriangleAlert,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/EstadoBadge.vue';
import InputError from '@/components/InputError.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatearFecha, formatearFechaHora } from '@/lib/reservas';
import { edit, transicion } from '@/routes/reservas';
import { index as solicitudes } from '@/routes/solicitudes';
import type {
    AccionFlujo,
    AdvertenciaRequerimiento,
    ObservacionVigente,
    ReservaVista,
} from '@/types';

const props = defineProps<{
    reserva: ReservaVista;
    puede: { editar: boolean };
    acciones: AccionFlujo[];
    traslapes: string[];
    advertencias: AdvertenciaRequerimiento[];
    observacionVigente: ObservacionVigente | null;
}>();

// Título, ícono y color del recuadro según el tipo de la observación vigente.
const estiloObservacion: Record<
    ObservacionVigente['tipo'],
    { titulo: string; icono: Component; clases: string }
> = {
    correccion_solicitada: {
        titulo: 'Corrección solicitada',
        icono: TriangleAlert,
        clases: 'border-orange-200 bg-orange-50 text-orange-900',
    },
    autorizada: {
        titulo: 'Reserva autorizada',
        icono: CircleCheck,
        clases: 'border-green-200 bg-green-50 text-green-900',
    },
    denegada: {
        titulo: 'Reserva denegada',
        icono: CircleX,
        clases: 'border-red-200 bg-red-50 text-red-900',
    },
};

// Autor, rol y fecha disponibles (en registros antiguos pueden faltar: no se inventan).
const autoriaObservacion = computed(() =>
    props.observacionVigente
        ? [props.observacionVigente.autor, props.observacionVigente.rol]
              .filter(Boolean)
              .join(' · ')
        : '',
);

// Bloques con requerimientos que el catálogo ya no respalda (para marcarlos en la lista).
const bloquesConAdvertencia = computed(
    () => new Set(props.advertencias.map((a) => a.detalle_id)),
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Solicitudes', href: solicitudes() }],
    },
});

const page = usePage();

// Errores del flujo: transición inválida y validaciones estrictas (reserva.0, reserva.1, …).
const erroresFlujo = computed(() => {
    const errores = page.props.errors as Record<string, string>;

    return Object.entries(errores)
        .filter(
            ([clave]) => clave === 'transicion' || clave.startsWith('reserva.'),
        )
        .map(([, mensaje]) => mensaje);
});

type Paso = { etiqueta: string; icono: Component; color: string };

const gris = 'border-border bg-muted text-muted-foreground';
const ambar = 'border-amber-200 bg-amber-50 text-amber-700';

// Cada valor del ENUM historial_reservas.accion.
const pasos: Record<string, Paso> = {
    creada: { etiqueta: 'Creada', icono: FilePlus, color: gris },
    enviada_coordinador: {
        etiqueta: 'Enviada a coordinación',
        icono: Send,
        color: ambar,
    },
    editada_coordinador: {
        etiqueta: 'Editada por coordinación',
        icono: Pencil,
        color: 'border-sky-200 bg-sky-50 text-sky-700',
    },
    correccion_solicitada: {
        etiqueta: 'Corrección solicitada',
        icono: TriangleAlert,
        color: 'border-orange-200 bg-orange-50 text-orange-700',
    },
    aprobada_coordinador: {
        etiqueta: 'Aprobada por coordinación',
        icono: CheckCheck,
        color: ambar,
    },
    enviada_admin: {
        etiqueta: 'Enviada a administración',
        icono: Send,
        color: ambar,
    },
    autorizada: {
        etiqueta: 'Autorizada',
        icono: CircleCheck,
        color: 'border-green-200 bg-green-50 text-green-700',
    },
    denegada: {
        etiqueta: 'Denegada',
        icono: CircleX,
        color: 'border-red-200 bg-red-50 text-red-700',
    },
    cancelada: { etiqueta: 'Cancelada', icono: Ban, color: gris },
};

const pasoGenerico: Paso = { etiqueta: '', icono: Clock, color: gris };

const estiloDetalle: Record<string, string> = {
    pendiente: 'text-amber-700',
    autorizada: 'text-green-700',
    denegada: 'text-red-700',
    cancelada: 'text-muted-foreground',
};

const seleccionada = ref<AccionFlujo | null>(null);
const form = useForm({ accion: '', observacion: '' });

const dialogoAbierto = computed({
    get: () => seleccionada.value !== null,
    set: (valor) => {
        if (!valor) {
            seleccionada.value = null;
        }
    },
});

const abrir = (accion: AccionFlujo) => {
    form.clearErrors();
    form.accion = accion.accion;
    form.observacion = '';
    seleccionada.value = accion;
};

const confirmar = () => {
    form.post(transicion.url(props.reserva.id), {
        preserveScroll: true,
        onSuccess: () => {
            seleccionada.value = null;
        },
        onError: (errores) => {
            // Si el problema no es la observación, se cierra el diálogo para ver los errores.
            if (!errores.observacion) {
                seleccionada.value = null;
            }
        },
    });
};

const varianteBoton = (accion: AccionFlujo) =>
    accion.accion === 'denegar'
        ? 'destructive'
        : accion.accion === 'solicitar_correccion'
          ? 'outline'
          : 'default';
</script>

<template>
    <Head :title="`Reserva ${reserva.folio ?? ''}`" />

    <PaginaBase
        :titulo="`Reserva ${reserva.folio ?? ''}`"
        :descripcion="reserva.nombre_actividad || reserva.actividad"
    >
        <template #acciones>
            <div class="flex flex-wrap items-center gap-2">
                <EstadoBadge
                    :estado-id="reserva.estado.id"
                    :estado="reserva.estado.nombre"
                />
                <Button v-if="puede.editar" variant="outline" as-child>
                    <Link :href="edit(reserva.id)">
                        <Pencil class="size-4" />
                        Editar
                    </Link>
                </Button>
                <Button
                    v-for="accion in acciones"
                    :key="accion.accion"
                    :variant="varianteBoton(accion)"
                    @click="abrir(accion)"
                >
                    {{ accion.etiqueta }}
                </Button>
            </div>
        </template>

        <div
            v-if="erroresFlujo.length"
            class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"
            role="alert"
        >
            <p class="font-medium">No se pudo completar la acción:</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="mensaje in erroresFlujo" :key="mensaje">
                    {{ mensaje }}
                </li>
            </ul>
        </div>

        <div
            v-if="traslapes.length"
            class="flex gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <div>
                <p class="font-medium">Choques con reservas ya autorizadas</p>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="mensaje in traslapes" :key="mensaje">
                        {{ mensaje }}
                    </li>
                </ul>
                <p class="mt-1">No se podrá autorizar hasta resolverlos.</p>
            </div>
        </div>

        <div
            v-if="advertencias.length"
            class="flex gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <div>
                <p class="font-medium">
                    Requerimientos que ya no están disponibles
                </p>
                <ul class="mt-1 list-disc pl-5">
                    <li
                        v-for="advertencia in advertencias"
                        :key="advertencia.mensaje"
                    >
                        {{ advertencia.mensaje }}
                    </li>
                </ul>
                <p class="mt-1">
                    No se podrá enviar ni aprobar hasta ajustar los
                    requerimientos.
                </p>
            </div>
        </div>

        <div
            v-if="observacionVigente"
            class="flex gap-3 rounded-md border p-4 text-sm"
            :class="estiloObservacion[observacionVigente.tipo].clases"
        >
            <component
                :is="estiloObservacion[observacionVigente.tipo].icono"
                class="mt-0.5 size-4 shrink-0"
            />
            <div class="min-w-0 flex-1">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-3"
                >
                    <p class="font-medium">
                        {{ estiloObservacion[observacionVigente.tipo].titulo }}
                    </p>
                    <time
                        v-if="observacionVigente.fecha"
                        :datetime="observacionVigente.fecha"
                        class="text-xs opacity-80"
                        >{{
                            formatearFechaHora(observacionVigente.fecha)
                        }}</time
                    >
                </div>
                <p v-if="autoriaObservacion" class="text-xs opacity-80">
                    {{ autoriaObservacion }}
                </p>
                <p
                    v-if="observacionVigente.comentario?.trim()"
                    class="mt-1 whitespace-pre-line"
                >
                    {{ observacionVigente.comentario }}
                </p>
                <p v-else class="mt-1 italic opacity-70">
                    Sin observaciones adicionales.
                </p>
            </div>
        </div>

        <TarjetaSeccion titulo="Datos generales">
            <dl class="grid gap-4 p-4 text-sm sm:grid-cols-2 md:p-6">
                <div>
                    <dt class="text-muted-foreground">Solicitante</dt>
                    <dd class="font-medium">{{ reserva.creador }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Coordinador</dt>
                    <dd class="font-medium">
                        {{ reserva.coordinador ?? 'Sin asignar' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Licenciatura</dt>
                    <dd class="font-medium">{{ reserva.licenciatura }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Tipo de actividad</dt>
                    <dd class="font-medium">{{ reserva.actividad }}</dd>
                </div>
                <div v-if="reserva.cuatrimestre">
                    <dt class="text-muted-foreground">Cuatrimestre</dt>
                    <dd class="font-medium">{{ reserva.cuatrimestre }}</dd>
                </div>
                <div v-if="reserva.asignatura">
                    <dt class="text-muted-foreground">Asignatura</dt>
                    <dd class="font-medium">{{ reserva.asignatura }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground">Objetivo</dt>
                    <dd class="whitespace-pre-line">
                        {{ reserva.objetivo || 'Sin capturar' }}
                    </dd>
                </div>
            </dl>
        </TarjetaSeccion>

        <TarjetaSeccion
            titulo="Espacios, fechas y horarios"
            :descripcion="`${reserva.detalles.length} espacio(s)`"
        >
            <p
                v-if="reserva.detalles.length === 0"
                class="px-4 py-8 text-center text-sm text-muted-foreground"
            >
                Aún no hay espacios en esta reserva.
            </p>
            <ul v-else class="divide-y divide-border">
                <li
                    v-for="detalle in reserva.detalles"
                    :key="detalle.id ?? 0"
                    class="grid gap-1 p-4 text-sm md:grid-cols-4 md:p-6"
                >
                    <div class="font-medium md:col-span-2">
                        {{ detalle.espacio.nombre }}
                        <span
                            v-if="detalle.espacio.ubicacion"
                            class="font-normal text-muted-foreground"
                            >· {{ detalle.espacio.ubicacion }}</span
                        >
                    </div>
                    <div>
                        {{ formatearFecha(detalle.fecha) }},
                        {{ detalle.hora_inicio }} – {{ detalle.hora_fin }}
                    </div>
                    <div
                        class="capitalize"
                        :class="
                            estiloDetalle[detalle.estado_detalle ?? 'pendiente']
                        "
                    >
                        {{ detalle.estado_detalle ?? 'pendiente' }}
                    </div>
                    <div
                        v-if="detalle.cantidad_alumnos"
                        class="text-muted-foreground md:col-span-4"
                    >
                        {{ detalle.cantidad_alumnos }} alumnos
                        <template v-if="detalle.espacio.capacidad">
                            (capacidad {{ detalle.espacio.capacidad }})
                        </template>
                    </div>
                    <div
                        v-if="detalle.docente_responsable"
                        class="text-muted-foreground md:col-span-4"
                    >
                        Responsable: {{ detalle.docente_responsable }}
                    </div>
                    <div
                        v-if="detalle.requerimientos_vista.length"
                        class="text-muted-foreground md:col-span-4"
                    >
                        Requerimientos:
                        {{
                            detalle.requerimientos_vista
                                .map((r) => `${r.nombre} (${r.cantidad})`)
                                .join(', ')
                        }}
                    </div>
                    <div
                        v-if="
                            detalle.id !== null &&
                            bloquesConAdvertencia.has(detalle.id)
                        "
                        class="text-amber-700 md:col-span-4"
                    >
                        Revisa los requerimientos de este espacio.
                    </div>
                    <div
                        v-if="detalle.descripcion_actividad?.trim()"
                        class="text-muted-foreground md:col-span-4"
                    >
                        <span class="font-medium text-foreground"
                            >Descripción de la actividad:</span
                        >
                        <p class="whitespace-pre-line">
                            {{ detalle.descripcion_actividad }}
                        </p>
                    </div>
                    <div
                        v-if="detalle.material_requerido"
                        class="text-muted-foreground md:col-span-4"
                    >
                        Material: {{ detalle.material_requerido }}
                    </div>
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion
            titulo="Línea de tiempo"
            :descripcion="`${reserva.historial.length} movimiento(s)`"
        >
            <p
                v-if="reserva.historial.length === 0"
                class="px-4 py-8 text-center text-sm text-muted-foreground"
            >
                Sin movimientos registrados.
            </p>
            <ol v-else class="relative p-4 md:p-6">
                <li
                    v-for="(registro, i) in reserva.historial"
                    :key="registro.id"
                    class="relative flex gap-4 pb-6 last:pb-0"
                >
                    <!-- Línea que une los puntos -->
                    <span
                        v-if="i < reserva.historial.length - 1"
                        class="absolute top-8 bottom-0 left-4 w-px -translate-x-1/2 bg-border"
                        aria-hidden="true"
                    />
                    <span
                        class="relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border"
                        :class="(pasos[registro.accion] ?? pasoGenerico).color"
                    >
                        <component
                            :is="(pasos[registro.accion] ?? pasoGenerico).icono"
                            class="size-4"
                        />
                    </span>
                    <div class="min-w-0 flex-1 pt-1 text-sm">
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-x-3"
                        >
                            <span class="font-medium">
                                {{
                                    (pasos[registro.accion] ?? pasoGenerico)
                                        .etiqueta || registro.accion
                                }}
                            </span>
                            <time
                                v-if="registro.fecha"
                                :datetime="registro.fecha"
                                class="text-xs text-muted-foreground"
                                >{{ formatearFechaHora(registro.fecha) }}</time
                            >
                        </div>
                        <p class="text-muted-foreground">
                            {{ registro.usuario }}
                        </p>
                        <p
                            v-if="registro.comentario"
                            class="mt-1 rounded-md bg-muted px-3 py-2 whitespace-pre-line"
                        >
                            {{ registro.comentario }}
                        </p>
                    </div>
                </li>
            </ol>
        </TarjetaSeccion>
    </PaginaBase>

    <Dialog v-model:open="dialogoAbierto">
        <DialogContent v-if="seleccionada">
            <DialogHeader>
                <DialogTitle>{{ seleccionada.etiqueta }}</DialogTitle>
                <DialogDescription>
                    {{
                        seleccionada.requiereObservacion
                            ? 'Escribe una observación: es obligatoria y la verá el solicitante.'
                            : 'Puedes agregar un comentario opcional.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="confirmar">
                <div class="grid gap-1.5">
                    <textarea
                        v-model="form.observacion"
                        :required="seleccionada.requiereObservacion"
                        maxlength="5000"
                        rows="4"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm"
                        :placeholder="
                            seleccionada.requiereObservacion
                                ? 'Observación'
                                : 'Comentario (opcional)'
                        "
                    />
                    <InputError :message="form.errors.observacion" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="seleccionada = null"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :variant="varianteBoton(seleccionada)"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        Confirmar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
