<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, TriangleAlert } from '@lucide/vue';
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
import { formatearFecha } from '@/lib/reservas';
import { edit, transicion } from '@/routes/reservas';
import { index as solicitudes } from '@/routes/solicitudes';
import type { AccionFlujo, ReservaVista } from '@/types';

const props = defineProps<{
    reserva: ReservaVista;
    puede: { editar: boolean };
    acciones: AccionFlujo[];
    traslapes: string[];
}>();

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

const etiquetasHistorial: Record<string, string> = {
    creada: 'Creada',
    enviada_coordinador: 'Enviada a coordinación',
    correccion_solicitada: 'Corrección solicitada',
    aprobada_coordinador: 'Aprobada por coordinación',
    enviada_admin: 'Enviada a administración',
    autorizada: 'Autorizada',
    denegada: 'Denegada',
    cancelada: 'Cancelada',
};

const estiloDetalle: Record<string, string> = {
    pendiente: 'text-amber-700',
    autorizada: 'text-green-700',
    denegada: 'text-red-700',
    cancelada: 'text-muted-foreground',
};

const formatoFechaHora = new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

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
            v-if="reserva.observaciones_admin"
            class="rounded-md border border-orange-200 bg-orange-50 p-4 text-sm text-orange-900"
        >
            <p class="font-medium">Última observación</p>
            <p class="mt-1 whitespace-pre-line">
                {{ reserva.observaciones_admin }}
            </p>
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
                        :class="estiloDetalle[detalle.estado_detalle ?? 'pendiente']"
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
                        v-if="detalle.material_requerido"
                        class="text-muted-foreground md:col-span-4"
                    >
                        Material: {{ detalle.material_requerido }}
                    </div>
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Historial">
            <ol class="divide-y divide-border">
                <li
                    v-for="registro in reserva.historial"
                    :key="registro.id"
                    class="p-4 text-sm"
                >
                    <div class="flex flex-wrap justify-between gap-2">
                        <span class="font-medium">
                            {{ etiquetasHistorial[registro.accion] ?? registro.accion }}
                        </span>
                        <span class="text-muted-foreground">
                            {{ registro.usuario }}
                            <template v-if="registro.fecha">
                                · {{ formatoFechaHora.format(new Date(registro.fecha)) }}
                            </template>
                        </span>
                    </div>
                    <p
                        v-if="registro.comentario"
                        class="mt-1 whitespace-pre-line text-muted-foreground"
                    >
                        {{ registro.comentario }}
                    </p>
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
