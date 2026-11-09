<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import InputError from '@/components/InputError.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { show, store, update } from '@/routes/reservas';
import { index as solicitudes } from '@/routes/solicitudes';
import type {
    DetalleFormulario,
    OpcionesReserva,
    ReservaFormulario,
} from '@/types';

const props = defineProps<{
    reserva: ReservaFormulario | null;
    opciones: OpcionesReserva;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Solicitudes', href: solicitudes() }],
    },
});

const esEdicion = computed(() => props.reserva !== null);
const titulo = computed(() =>
    props.reserva
        ? `Editar reserva ${props.reserva.folio ?? ''}`.trim()
        : 'Nueva reserva',
);

// CampoSelect trabaja con texto: el espacio se edita como string y se envía como número.
// Los <Input> no aceptan null: los opcionales vacíos van como '' (Laravel los vuelve null).
type DetalleEditable = Omit<
    DetalleFormulario,
    'espacio_id' | 'cantidad_alumnos' | 'lugar_especifico'
> & {
    espacio_id: string | null;
    cantidad_alumnos: number | '';
    lugar_especifico: string;
};

const detalleVacio = (): DetalleEditable => ({
    id: null,
    espacio_id: null,
    fecha: '',
    hora_inicio: '',
    hora_fin: '',
    cantidad_alumnos: '',
    lugar_especifico: '',
    descripcion_actividad: null,
    material_requerido: null,
    observaciones: null,
});

const aTexto = (valor: number | null | undefined) =>
    valor === null || valor === undefined ? null : String(valor);

const form = useForm({
    licenciatura_id: aTexto(
        props.reserva?.licenciatura_id ??
            (props.opciones.licenciaturas.length === 1
                ? props.opciones.licenciaturas[0].id
                : null),
    ),
    actividad_id: aTexto(props.reserva?.actividad_id),
    nombre_actividad: props.reserva?.nombre_actividad ?? '',
    objetivo: props.reserva?.objetivo ?? '',
    tipo_formato: props.reserva?.tipo_formato ?? 'normal',
    cuatrimestre_id: aTexto(props.reserva?.cuatrimestre_id),
    asignatura_id: aTexto(props.reserva?.asignatura_id),
    costo_alumno: props.reserva?.costo_alumno ?? '',
    promocion_redes: props.reserva?.promocion_redes ?? 'no',
    limite_min_usuarios: (props.reserva?.limite_min_usuarios ?? '') as
        | number
        | '',
    limite_max_usuarios: (props.reserva?.limite_max_usuarios ?? '') as
        | number
        | '',
    fecha_entrega_formato: props.reserva?.fecha_entrega_formato ?? '',
    detalles: (props.reserva?.detalles ?? []).map((d): DetalleEditable => ({
        ...d,
        espacio_id: aTexto(d.espacio_id),
        cantidad_alumnos: d.cantidad_alumnos ?? '',
        lugar_especifico: d.lugar_especifico ?? '',
    })),
});

const asignaturas = computed(() =>
    props.opciones.asignaturas.filter(
        (a) =>
            String(a.licenciatura_id) === form.licenciatura_id &&
            (!form.cuatrimestre_id ||
                String(a.cuatrimestre_id) === form.cuatrimestre_id),
    ),
);

const opcionesEspacios = computed(() =>
    props.opciones.espacios.map((e) => ({
        id: e.id,
        nombre:
            e.nombre +
            (e.capacidad ? ` · ${e.capacidad} lugares` : '') +
            (e.estatus !== 'disponible' ? ' (en mantenimiento)' : ''),
    })),
);

const capacidad = (espacioId: string | null) =>
    props.opciones.espacios.find((e) => String(e.id) === String(espacioId))
        ?.capacidad ?? null;

const errorDetalle = (i: number, campo: string) =>
    (form.errors as Record<string, string | undefined>)[
        `detalles.${i}.${campo}`
    ];

const agregarDetalle = () => form.detalles.push(detalleVacio());
const quitarDetalle = (i: number) => form.detalles.splice(i, 1);

const guardar = () => {
    const opciones = { preserveScroll: true };

    form.transform((datos) => ({
        ...datos,
        detalles: datos.detalles.map((d) => ({
            ...d,
            espacio_id: d.espacio_id === null ? null : Number(d.espacio_id),
        })),
    }));

    if (props.reserva) {
        form.put(update.url(props.reserva.id), opciones);
    } else {
        form.post(store.url(), opciones);
    }
};

const textarea =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <Head :title="titulo" />

    <PaginaBase
        :titulo="titulo"
        :descripcion="
            esEdicion
                ? 'Los cambios se guardan sin cambiar el estado de la reserva.'
                : 'Se guardará como borrador. Puedes enviarla a revisión desde su detalle.'
        "
    >
        <div
            v-if="opciones.licenciaturas.length === 0"
            class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
        >
            No tienes licenciaturas asignadas. Pide al administrador que te
            asigne a una para poder registrar reservas.
        </div>
        <div
            v-else-if="opciones.actividades.length === 0"
            class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
        >
            Aún no hay tipos de actividad registrados. Pide al administrador que
            los dé de alta.
        </div>

        <form class="grid gap-6" @submit.prevent="guardar">
            <TarjetaSeccion titulo="Datos generales">
                <div class="grid gap-4 p-4 md:grid-cols-2 md:p-6">
                    <CampoFormulario
                        id="licenciatura_id"
                        etiqueta="Licenciatura"
                        :error="form.errors.licenciatura_id"
                    >
                        <CampoSelect
                            id="licenciatura_id"
                            v-model="form.licenciatura_id"
                            :opciones="opciones.licenciaturas"
                            vacio="Selecciona una licenciatura"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="actividad_id"
                        etiqueta="Tipo de actividad"
                        :error="form.errors.actividad_id"
                    >
                        <CampoSelect
                            id="actividad_id"
                            v-model="form.actividad_id"
                            :opciones="opciones.actividades"
                            vacio="Selecciona un tipo"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="nombre_actividad"
                        etiqueta="Nombre de la actividad"
                        :error="form.errors.nombre_actividad"
                        class="md:col-span-2"
                    >
                        <Input
                            id="nombre_actividad"
                            v-model="form.nombre_actividad"
                            maxlength="255"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="objetivo"
                        etiqueta="Objetivo"
                        :error="form.errors.objetivo"
                        class="md:col-span-2"
                    >
                        <textarea
                            id="objetivo"
                            v-model="form.objetivo"
                            :class="textarea"
                            maxlength="5000"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="cuatrimestre_id"
                        etiqueta="Cuatrimestre (opcional)"
                        :error="form.errors.cuatrimestre_id"
                    >
                        <CampoSelect
                            id="cuatrimestre_id"
                            v-model="form.cuatrimestre_id"
                            :opciones="opciones.cuatrimestres"
                            vacio="Sin cuatrimestre"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="asignatura_id"
                        etiqueta="Asignatura (opcional)"
                        :error="form.errors.asignatura_id"
                    >
                        <CampoSelect
                            id="asignatura_id"
                            v-model="form.asignatura_id"
                            :opciones="asignaturas"
                            vacio="Sin asignatura"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="tipo_formato"
                        etiqueta="Tipo de formato"
                        :error="form.errors.tipo_formato"
                    >
                        <select
                            id="tipo_formato"
                            v-model="form.tipo_formato"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs md:text-sm"
                        >
                            <option value="normal">Normal</option>
                            <option value="ext">Extracurricular</option>
                        </select>
                    </CampoFormulario>
                    <CampoFormulario
                        id="promocion_redes"
                        etiqueta="¿Promoción en redes?"
                        :error="form.errors.promocion_redes"
                    >
                        <select
                            id="promocion_redes"
                            v-model="form.promocion_redes"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs md:text-sm"
                        >
                            <option value="no">No</option>
                            <option value="si">Sí</option>
                        </select>
                    </CampoFormulario>
                    <CampoFormulario
                        id="costo_alumno"
                        etiqueta="Costo por alumno (opcional)"
                        :error="form.errors.costo_alumno"
                    >
                        <Input
                            id="costo_alumno"
                            v-model="form.costo_alumno"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="fecha_entrega_formato"
                        etiqueta="Fecha de entrega del formato (opcional)"
                        :error="form.errors.fecha_entrega_formato"
                    >
                        <Input
                            id="fecha_entrega_formato"
                            v-model="form.fecha_entrega_formato"
                            type="date"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="limite_min_usuarios"
                        etiqueta="Mínimo de participantes (opcional)"
                        :error="form.errors.limite_min_usuarios"
                    >
                        <Input
                            id="limite_min_usuarios"
                            v-model.number="form.limite_min_usuarios"
                            type="number"
                            min="0"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="limite_max_usuarios"
                        etiqueta="Máximo de participantes (opcional)"
                        :error="form.errors.limite_max_usuarios"
                    >
                        <Input
                            id="limite_max_usuarios"
                            v-model.number="form.limite_max_usuarios"
                            type="number"
                            min="0"
                        />
                    </CampoFormulario>
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion
                titulo="Espacios, fechas y horarios"
                descripcion="Puedes guardar sin espacios; se exigen al enviar a administración."
            >
                <template #acciones>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="agregarDetalle"
                    >
                        <Plus class="size-4" />
                        Agregar espacio
                    </Button>
                </template>

                <p
                    v-if="form.detalles.length === 0"
                    class="px-4 py-8 text-center text-sm text-muted-foreground"
                >
                    Aún no hay espacios en esta reserva.
                </p>

                <div
                    v-for="(detalle, i) in form.detalles"
                    :key="detalle.id ?? `nuevo-${i}`"
                    class="grid gap-4 border-t border-border p-4 first:border-t-0 md:grid-cols-4 md:p-6"
                >
                    <div
                        class="flex items-center justify-between md:col-span-4"
                    >
                        <h3 class="text-sm font-semibold">
                            Espacio {{ i + 1 }}
                        </h3>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="quitarDetalle(i)"
                        >
                            <Trash2 class="size-4" />
                            Quitar
                        </Button>
                    </div>
                    <CampoFormulario
                        :id="`espacio-${i}`"
                        etiqueta="Espacio"
                        :error="errorDetalle(i, 'espacio_id')"
                        class="md:col-span-2"
                    >
                        <CampoSelect
                            :id="`espacio-${i}`"
                            v-model="detalle.espacio_id"
                            :opciones="opcionesEspacios"
                            vacio="Selecciona un espacio"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        :id="`alumnos-${i}`"
                        :etiqueta="
                            capacidad(detalle.espacio_id)
                                ? `Alumnos (máx. ${capacidad(detalle.espacio_id)})`
                                : 'Alumnos'
                        "
                        :error="errorDetalle(i, 'cantidad_alumnos')"
                        class="md:col-span-2"
                    >
                        <Input
                            :id="`alumnos-${i}`"
                            v-model.number="detalle.cantidad_alumnos"
                            type="number"
                            min="1"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        :id="`fecha-${i}`"
                        etiqueta="Fecha"
                        :error="errorDetalle(i, 'fecha')"
                        class="md:col-span-2"
                    >
                        <Input
                            :id="`fecha-${i}`"
                            v-model="detalle.fecha"
                            type="date"
                            required
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        :id="`inicio-${i}`"
                        etiqueta="Hora de inicio"
                        :error="errorDetalle(i, 'hora_inicio')"
                    >
                        <Input
                            :id="`inicio-${i}`"
                            v-model="detalle.hora_inicio"
                            type="time"
                            required
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        :id="`fin-${i}`"
                        etiqueta="Hora de fin"
                        :error="errorDetalle(i, 'hora_fin')"
                    >
                        <Input
                            :id="`fin-${i}`"
                            v-model="detalle.hora_fin"
                            type="time"
                            required
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        :id="`lugar-${i}`"
                        etiqueta="Lugar específico (opcional)"
                        :error="errorDetalle(i, 'lugar_especifico')"
                        class="md:col-span-4"
                    >
                        <Input
                            :id="`lugar-${i}`"
                            v-model="detalle.lugar_especifico"
                            maxlength="150"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        :id="`descripcion-${i}`"
                        etiqueta="Descripción de la actividad (opcional)"
                        :error="errorDetalle(i, 'descripcion_actividad')"
                        class="md:col-span-2"
                    >
                        <textarea
                            :id="`descripcion-${i}`"
                            v-model="detalle.descripcion_actividad"
                            :class="textarea"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        :id="`material-${i}`"
                        etiqueta="Material requerido (opcional)"
                        :error="errorDetalle(i, 'material_requerido')"
                        class="md:col-span-2"
                    >
                        <textarea
                            :id="`material-${i}`"
                            v-model="detalle.material_requerido"
                            :class="textarea"
                        />
                    </CampoFormulario>
                </div>
                <InputError class="px-4 pb-4" :message="form.errors.detalles" />
            </TarjetaSeccion>

            <div class="flex flex-wrap gap-3">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    {{ esEdicion ? 'Guardar cambios' : 'Guardar borrador' }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="reserva ? show(reserva.id) : solicitudes()"
                        >Cancelar</Link
                    >
                </Button>
            </div>
        </form>
    </PaginaBase>
</template>
