<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Ban,
    CheckCheck,
    CircleCheck,
    CircleX,
    Clock,
    FilePlus,
    Pencil,
    Printer,
    Send,
    TriangleAlert,
    Undo2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
import {
    etiquetaAccion,
    folioVisible,
    formatearFechaHora,
} from '@/lib/reservas';
import { edit, imprimir, transicion } from '@/routes/reservas';
import { index as solicitudes } from '@/routes/solicitudes';
import type {
    AccionFlujo,
    AdvertenciaRequerimiento,
    CasillaAutorizacion,
    ObservacionVigente,
    ReservaVista,
} from '@/types';

// VER SOLICITUD: el documento es la vista previa real del Formato 10 (la misma plantilla y
// ruta que la impresión, con ?vista=previa); la gestión del flujo va en un panel aparte,
// fuera de la hoja. Los permisos de cada acción los decide el servidor.
const props = defineProps<{
    reserva: ReservaVista;
    puede: { editar: boolean };
    acciones: AccionFlujo[];
    traslapes: string[];
    advertencias: AdvertenciaRequerimiento[];
    observacionVigente: ObservacionVigente | null;
    autorizaciones: CasillaAutorizacion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Solicitudes', href: solicitudes() }],
    },
});

const folio = computed(() => folioVisible(props.reserva) ?? '');

// --- Vista previa (iframe del mismo origen; el servidor vuelve a autorizar la ruta) ---
const previa = ref<HTMLIFrameElement | null>(null);
const altoPrevia = ref(1100);
const urlPrevia = imprimir.url(props.reserva.id, {
    query: { vista: 'previa' },
});

const recibirAltura = (evento: MessageEvent) => {
    if (
        evento.origin !== window.location.origin ||
        evento.source !== previa.value?.contentWindow ||
        evento.data?.tipo !== 'formato10-altura'
    ) {
        return;
    }

    const alto = Number(evento.data.alto);

    if (Number.isFinite(alto) && alto > 0) {
        altoPrevia.value = alto;
    }
};

onMounted(() => window.addEventListener('message', recibirAltura));
onBeforeUnmount(() => window.removeEventListener('message', recibirAltura));

// --- IMPRIMIR: la versión imprimible aprobada (ruta normal), en un marco invisible ---
const urlImpresion = ref<string | null>(null);
const imprimiendo = ref(false);

const imprimirDocumento = () => {
    imprimiendo.value = true;
    // Cada clic recarga el marco para imprimir siempre los datos actuales.
    urlImpresion.value = `${imprimir.url(props.reserva.id)}?t=${Date.now()}`;
};

const alCargarImpresion = (evento: Event) => {
    const ventana = (evento.target as HTMLIFrameElement).contentWindow;
    imprimiendo.value = false;
    ventana?.focus();
    ventana?.print();
};

// --- Panel de gestión ---
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
        titulo: 'Reserva rechazada',
        icono: CircleX,
        clases: 'border-red-200 bg-red-50 text-red-900',
    },
};

const autoriaObservacion = computed(() =>
    props.observacionVigente
        ? [props.observacionVigente.autor, props.observacionVigente.rol]
              .filter(Boolean)
              .join(' · ')
        : '',
);

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
    editada_admin: {
        etiqueta: 'Editada por administración',
        icono: Pencil,
        color: 'border-indigo-200 bg-indigo-50 text-indigo-700',
    },
    correccion_solicitada: {
        etiqueta: 'Mandada a corregir',
        icono: TriangleAlert,
        color: 'border-orange-200 bg-orange-50 text-orange-700',
    },
    aprobada_coordinador: {
        etiqueta: 'Enviada a directivo',
        icono: CheckCheck,
        color: ambar,
    },
    enviada_admin: {
        etiqueta: 'Enviada a directivo',
        icono: Send,
        color: ambar,
    },
    autorizada: {
        etiqueta: 'Autorizada',
        icono: CircleCheck,
        color: 'border-green-200 bg-green-50 text-green-700',
    },
    denegada: {
        etiqueta: 'Rechazada',
        icono: CircleX,
        color: 'border-red-200 bg-red-50 text-red-700',
    },
    cancelada: { etiqueta: 'Cancelada', icono: Ban, color: gris },
};

const pasoGenerico: Paso = { etiqueta: '', icono: Clock, color: gris };

const iconoAccion: Record<AccionFlujo['accion'], Component> = {
    enviar: Send,
    aprobar: Send,
    autorizar: CircleCheck,
    denegar: CircleX,
    solicitar_correccion: Undo2,
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
    <Head :title="`Reserva ${folio}`" />

    <PaginaBase
        :titulo="`Reserva ${folio}`"
        :descripcion="reserva.nombre_actividad || reserva.actividad"
    >
        <template #acciones>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" as-child>
                    <Link :href="solicitudes()">
                        <ArrowLeft class="size-4" />
                        Volver atrás
                    </Link>
                </Button>
                <Button :disabled="imprimiendo" @click="imprimirDocumento">
                    <Spinner v-if="imprimiendo" />
                    <Printer v-else class="size-4" />
                    Imprimir
                </Button>
            </div>
        </template>

        <div
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start"
        >
            <!-- Documento: vista previa real del Formato 10 (misma plantilla que la impresión). -->
            <section
                aria-label="Vista previa del Formato 10"
                class="min-w-0 overflow-hidden rounded-xl border border-border bg-muted/60 p-2 sm:p-4"
            >
                <iframe
                    ref="previa"
                    :src="urlPrevia"
                    title="Vista previa del Formato 10"
                    class="block w-full border-0"
                    :style="{ height: `${altoPrevia}px` }"
                />
            </section>

            <!-- Gestión: separada de la hoja; no aparece en el documento ni en el PDF. -->
            <aside
                aria-label="Gestión de la solicitud"
                class="grid gap-4 lg:sticky lg:top-20"
            >
                <TarjetaSeccion titulo="Gestión de la solicitud">
                    <div class="grid gap-3 p-4 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-muted-foreground">Estado:</span>
                            <EstadoBadge
                                :estado-id="reserva.estado.id"
                                :estado="reserva.estado.nombre"
                            />
                        </div>

                        <div
                            v-if="erroresFlujo.length"
                            class="rounded-md border border-red-200 bg-red-50 p-3 text-red-800"
                            role="alert"
                        >
                            <p class="font-medium">
                                No se pudo completar la acción:
                            </p>
                            <ul class="mt-1 list-disc pl-5">
                                <li
                                    v-for="mensaje in erroresFlujo"
                                    :key="mensaje"
                                >
                                    {{ mensaje }}
                                </li>
                            </ul>
                        </div>

                        <div
                            v-if="observacionVigente"
                            class="flex gap-2 rounded-md border p-3"
                            :class="
                                estiloObservacion[observacionVigente.tipo]
                                    .clases
                            "
                        >
                            <component
                                :is="
                                    estiloObservacion[observacionVigente.tipo]
                                        .icono
                                "
                                class="mt-0.5 size-4 shrink-0"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">
                                    {{
                                        estiloObservacion[
                                            observacionVigente.tipo
                                        ].titulo
                                    }}
                                </p>
                                <p
                                    v-if="
                                        autoriaObservacion ||
                                        observacionVigente.fecha
                                    "
                                    class="text-xs opacity-80"
                                >
                                    {{ autoriaObservacion }}
                                    <template v-if="observacionVigente.fecha">
                                        ·
                                        {{
                                            formatearFechaHora(
                                                observacionVigente.fecha,
                                            )
                                        }}
                                    </template>
                                </p>
                                <p
                                    v-if="observacionVigente.comentario?.trim()"
                                    class="mt-1 whitespace-pre-line"
                                >
                                    {{ observacionVigente.comentario }}
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="traslapes.length"
                            class="flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-800"
                        >
                            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                            <div>
                                <p class="font-medium">
                                    Choques con reservas ya autorizadas
                                </p>
                                <ul class="mt-1 list-disc pl-5">
                                    <li
                                        v-for="mensaje in traslapes"
                                        :key="mensaje"
                                    >
                                        {{ mensaje }}
                                    </li>
                                </ul>
                                <p class="mt-1">
                                    No se podrá autorizar hasta resolverlos.
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="advertencias.length"
                            class="flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-800"
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
                            </div>
                        </div>

                        <div
                            v-if="puede.editar || acciones.length"
                            class="grid gap-2"
                        >
                            <Button
                                v-if="puede.editar"
                                variant="outline"
                                class="justify-start"
                                as-child
                            >
                                <Link :href="edit(reserva.id)">
                                    <Pencil class="size-4" />
                                    Editar solicitud
                                </Link>
                            </Button>
                            <Button
                                v-for="accion in acciones"
                                :key="accion.accion"
                                :variant="varianteBoton(accion)"
                                class="justify-start"
                                @click="abrir(accion)"
                            >
                                <component
                                    :is="iconoAccion[accion.accion]"
                                    class="size-4"
                                />
                                {{ etiquetaAccion(accion) }}
                            </Button>
                        </div>
                        <p v-else class="text-muted-foreground">
                            No tienes acciones pendientes en esta solicitud.
                        </p>
                    </div>
                </TarjetaSeccion>

                <TarjetaSeccion
                    titulo="Historial"
                    :descripcion="`${reserva.historial.length} movimiento(s)`"
                >
                    <p
                        v-if="reserva.historial.length === 0"
                        class="px-4 py-6 text-center text-sm text-muted-foreground"
                    >
                        Sin movimientos registrados.
                    </p>
                    <ol v-else class="relative p-4">
                        <li
                            v-for="(registro, i) in reserva.historial"
                            :key="registro.id"
                            class="relative flex gap-3 pb-5 last:pb-0"
                        >
                            <span
                                v-if="i < reserva.historial.length - 1"
                                class="absolute top-8 bottom-0 left-4 w-px -translate-x-1/2 bg-border"
                                aria-hidden="true"
                            />
                            <span
                                class="relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border"
                                :class="
                                    (pasos[registro.accion] ?? pasoGenerico)
                                        .color
                                "
                            >
                                <component
                                    :is="
                                        (pasos[registro.accion] ?? pasoGenerico)
                                            .icono
                                    "
                                    class="size-4"
                                />
                            </span>
                            <div class="min-w-0 flex-1 pt-1 text-sm">
                                <p class="font-medium">
                                    {{
                                        (pasos[registro.accion] ?? pasoGenerico)
                                            .etiqueta || registro.accion
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ registro.usuario }}
                                    <template v-if="registro.fecha">
                                        ·
                                        {{ formatearFechaHora(registro.fecha) }}
                                    </template>
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
            </aside>
        </div>
    </PaginaBase>

    <!-- Marco invisible para imprimir la versión imprimible aprobada. -->
    <iframe
        v-if="urlImpresion"
        :src="urlImpresion"
        title="Impresión del Formato 10"
        aria-hidden="true"
        tabindex="-1"
        class="pointer-events-none fixed right-0 bottom-0 size-0 border-0 opacity-0"
        @load="alCargarImpresion"
    />

    <Dialog v-model:open="dialogoAbierto">
        <DialogContent v-if="seleccionada">
            <DialogHeader>
                <DialogTitle
                    >{{ etiquetaAccion(seleccionada) }} ·
                    {{ folio }}</DialogTitle
                >
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
