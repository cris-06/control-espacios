<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import BloqueReserva from '@/components/BloqueReserva.vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import InputError from '@/components/InputError.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { aMinutos } from '@/lib/reservas';
import { show, store, update } from '@/routes/reservas';
import { index as solicitudes } from '@/routes/solicitudes';
import type {
    DetalleEditable,
    OpcionesReserva,
    ReservaFormulario,
} from '@/types';

const props = defineProps<{
    reserva: ReservaFormulario | null;
    opciones: OpcionesReserva;
    // Etiqueta de «Guardar y enviar», o null si quien edita no puede enviarla ahora.
    envio: string | null;
}>();

const page = usePage();

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

const aTexto = (valor: number | null | undefined) =>
    valor === null || valor === undefined ? null : String(valor);

// Por omisión, el responsable de un bloque nuevo es quien llena la solicitud
// (si es docente o coordinador de alguna de sus licenciaturas).
const responsablePorOmision = (): string | null => {
    const id = page.props.auth.user.id;

    return props.opciones.docentes.some((d) => d.id === id) ? String(id) : null;
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
    docente_responsable_id: responsablePorOmision(),
    requerimientos: [],
});

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
        docente_responsable_id: aTexto(d.docente_responsable_id),
        requerimientos: d.requerimientos.map((r) => ({ ...r })),
    })),
    enviar: false,
});

const asignaturas = computed(() =>
    props.opciones.asignaturas.filter(
        (a) =>
            String(a.licenciatura_id) === form.licenciatura_id &&
            (!form.cuatrimestre_id ||
                String(a.cuatrimestre_id) === form.cuatrimestre_id),
    ),
);

const errorDetalle = (i: number, campo: string) =>
    (form.errors as Record<string, string | undefined>)[
        `detalles.${i}.${campo}`
    ];

// Clave estable por bloque para que Vue no mezcle el estado de bloques nuevos al quitar uno.
let siguienteClave = 0;
const claves = ref(form.detalles.map(() => siguienteClave++));

const agregarDetalle = () => {
    form.detalles.push(detalleVacio());
    claves.value.push(siguienteClave++);
};

const quitarDetalle = (i: number) => {
    form.detalles.splice(i, 1);
    claves.value.splice(i, 1);
};

// Copia el bloque justo debajo, como bloque nuevo y sin fecha, para otra sesión.
const duplicarDetalle = (i: number) => {
    const original = form.detalles[i];

    form.detalles.splice(i + 1, 0, {
        ...original,
        id: null,
        fecha: '',
        requerimientos: original.requerimientos.map((r) => ({ ...r })),
    });
    claves.value.splice(i + 1, 0, siguienteClave++);
};

// Bloques de esta misma solicitud que se pisan (aviso; el servidor lo valida al enviar).
const traslapesInternos = computed(() =>
    form.detalles.map((a, i) =>
        form.detalles.flatMap((b, j) =>
            i !== j &&
            a.espacio_id !== null &&
            a.espacio_id === b.espacio_id &&
            a.fecha !== '' &&
            a.fecha === b.fecha &&
            a.hora_inicio &&
            a.hora_fin &&
            b.hora_inicio &&
            b.hora_fin &&
            aMinutos(a.hora_inicio) < aMinutos(b.hora_fin) &&
            aMinutos(b.hora_inicio) < aMinutos(a.hora_fin)
                ? [j + 1]
                : [],
        ),
    ),
);

const guardar = (enviar = false) => {
    const opciones = { preserveScroll: true };

    form.enviar = enviar;
    form.transform((datos) => ({
        ...datos,
        detalles: datos.detalles.map((d) => ({
            ...d,
            espacio_id: d.espacio_id === null ? null : Number(d.espacio_id),
            docente_responsable_id:
                d.docente_responsable_id === null
                    ? null
                    : Number(d.docente_responsable_id),
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
                : 'Se guardará como borrador. Puedes enviarla ahora con «Guardar y enviar» o después desde su detalle.'
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

        <form class="grid gap-6" @submit.prevent="guardar()">
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
                descripcion="Puedes guardar sin espacios; se exigen al enviar a administración. La disponibilidad mostrada es orientativa: se confirma al autorizar."
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

                <BloqueReserva
                    v-for="(_, i) in form.detalles"
                    :key="claves[i]"
                    v-model:detalle="form.detalles[i]"
                    :indice="i"
                    :espacios="opciones.espacios"
                    :docentes="opciones.docentes"
                    :licenciatura-id="form.licenciatura_id"
                    :reserva-id="reserva?.id ?? null"
                    :traslapa-con="traslapesInternos[i]"
                    :error="(campo: string) => errorDetalle(i, campo)"
                    @duplicar="duplicarDetalle(i)"
                    @quitar="quitarDetalle(i)"
                />
                <InputError class="px-4 pb-4" :message="form.errors.detalles" />
            </TarjetaSeccion>

            <div class="flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :variant="envio ? 'outline' : 'default'"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing && !form.enviar" />
                    {{ esEdicion ? 'Guardar cambios' : 'Guardar borrador' }}
                </Button>
                <Button
                    v-if="envio"
                    type="button"
                    :disabled="form.processing"
                    @click="guardar(true)"
                >
                    <Spinner v-if="form.processing && form.enviar" />
                    <Send v-else class="size-4" />
                    {{ envio }}
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
