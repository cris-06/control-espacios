<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Send, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import AutorizacionesReserva from '@/components/AutorizacionesReserva.vue';
import BloqueReserva from '@/components/BloqueReserva.vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import EncabezadoFormato from '@/components/EncabezadoFormato.vue';
import InputError from '@/components/InputError.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import TarjetaSeccion from '@/components/TarjetaSeccion.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { aMinutos, folioVisible, formatearFecha } from '@/lib/reservas';
import { show, store, update } from '@/routes/reservas';
import { index as solicitudes } from '@/routes/solicitudes';
import type {
    AnticipacionEnvio,
    CasillaAutorizacion,
    DetalleEditable,
    OpcionesReserva,
    ReservaFormulario,
} from '@/types';

const props = defineProps<{
    reserva: ReservaFormulario | null;
    opciones: OpcionesReserva;
    // Etiqueta de «Guardar y enviar», o null si quien edita no puede enviarla ahora.
    envio: string | null;
    // Casillas de firma generadas por el flujo (solo al editar; una reserva nueva aún no tiene).
    autorizaciones?: CasillaAutorizacion[];
    // Anticipación mínima calculada por el servidor (la regla se aplica en Laravel).
    anticipacion: AnticipacionEnvio;
}>();

// Una reserva nueva todavía no tiene decisiones: las tres casillas quedan pendientes.
const casillasAutorizacion = computed<CasillaAutorizacion[]>(
    () =>
        props.autorizaciones ?? [
            {
                clave: 'docente',
                titulo: 'Docente responsable',
                situacion: 'pendiente',
                etiqueta: 'En elaboración, sin enviar',
                nombre: page.props.auth.user.name,
                rol: null,
                fecha: null,
            },
            {
                clave: 'coordinacion',
                titulo: 'Coordinación',
                situacion: 'pendiente',
                etiqueta: 'Pendiente',
                nombre: null,
                rol: null,
                fecha: null,
            },
            {
                clave: 'administracion',
                titulo: 'Autorización',
                situacion: 'pendiente',
                etiqueta: 'Pendiente',
                nombre: null,
                rol: null,
                fecha: null,
            },
        ],
);

const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Solicitudes', href: solicitudes() }],
    },
});

const esEdicion = computed(() => props.reserva !== null);
const titulo = computed(() =>
    props.reserva
        ? `Editar reserva ${folioVisible(props.reserva) ?? ''}`.trim()
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
    // folio, fecha_creacion y fecha_entrega_formato no se envían: los fija el servidor.
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

// Anticipación (solo aviso; la valida el servidor). Antes del primer envío la deben cumplir
// todos los bloques; después, solo los nuevos o con otro espacio, fecha u horario.
const fechaMinima = computed(() =>
    formatearFecha(props.anticipacion.fecha_minima),
);
const originales = new Map(
    (props.reserva?.detalles ?? []).map((d) => [
        d.id,
        `${d.espacio_id}|${d.fecha}|${d.hora_inicio.slice(0, 5)}|${d.hora_fin.slice(0, 5)}`,
    ]),
);
const debeCumplirAnticipacion = (d: DetalleEditable) =>
    !props.anticipacion.ya_enviada ||
    d.id === null ||
    originales.get(d.id) !==
        `${d.espacio_id}|${d.fecha}|${d.hora_inicio.slice(0, 5)}|${d.hora_fin.slice(0, 5)}`;
const incumpleAnticipacion = (d: DetalleEditable) =>
    d.fecha !== '' &&
    d.fecha < props.anticipacion.fecha_minima &&
    debeCumplirAnticipacion(d);

// Resumen de lo pedido en cada espacio (se captura dentro de cada bloque; aquí solo se lee).
const resumenRequerimientos = computed(() =>
    form.detalles.flatMap((d, i) => {
        const espacio = props.opciones.espacios.find(
            (e) => String(e.id) === d.espacio_id,
        );

        if (!espacio) {
            return [];
        }

        return [
            {
                clave: claves.value[i],
                bloque: i + 1,
                espacio: espacio.nombre,
                // Fecha y horario unidos solo con lo que ya se capturó (sin separadores sueltos).
                cuando: [
                    d.fecha ? formatearFecha(d.fecha) : '',
                    d.hora_inicio && d.hora_fin
                        ? `${d.hora_inicio} – ${d.hora_fin}`
                        : '',
                ]
                    .filter(Boolean)
                    .join(' · '),
                requerimientos: d.requerimientos.map((r) => {
                    const nombre =
                        espacio.requerimientos.find(
                            (c) => c.id === r.requerimiento_id,
                        )?.nombre ?? 'Requerimiento';

                    return `${nombre} (${r.cantidad})`;
                }),
                material: d.material_requerido?.trim() ?? '',
                anticipacion: incumpleAnticipacion(d),
            },
        ];
    }),
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
            <!-- Encabezado institucional: folio y fechas automáticas (solo lectura). -->
            <EncabezadoFormato
                :folio="reserva?.folio ?? null"
                :folio-institucional="reserva?.folio_institucional ?? null"
                :fecha-creacion="reserva?.fecha_creacion ?? null"
                :fecha-entrega="reserva?.fecha_entrega_formato ?? null"
                :estado-id="reserva?.estado_id ?? null"
            />

            <TarjetaSeccion titulo="Indicaciones">
                <ol
                    class="grid list-decimal gap-1 py-4 pr-4 pl-9 text-sm text-muted-foreground md:py-6 md:pr-6 md:pl-11"
                >
                    <li>
                        Puedes guardar la solicitud como borrador y completarla
                        después.
                    </li>
                    <li>
                        Envíala con al menos {{ anticipacion.dias }} días
                        naturales de anticipación: hoy, la fecha más próxima
                        permitida es el
                        <strong class="font-semibold text-foreground">{{
                            fechaMinima
                        }}</strong
                        >.
                    </li>
                    <li>
                        El folio y las fechas de elaboración y entrega los
                        asigna el sistema; las autorizaciones de coordinación y
                        administración se registran en el sistema.
                    </li>
                </ol>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Datos generales">
                <div class="grid gap-4 p-4 md:grid-cols-2 md:p-6">
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
                        etiqueta="Asignatura vinculada (opcional)"
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
                    class="border-b border-border px-4 py-3 text-sm text-muted-foreground md:px-6"
                >
                    <template v-if="anticipacion.ya_enviada">
                        Las fechas que ya enviaste se conservan. Si agregas un
                        espacio o cambias el espacio, la fecha o el horario de
                        uno, debe ser a partir del
                        <strong class="font-semibold text-foreground">{{
                            fechaMinima
                        }}</strong>
                        ({{ anticipacion.dias }} días de anticipación).
                    </template>
                    <template v-else>
                        Para enviar la solicitud, cada espacio debe ser a partir
                        del
                        <strong class="font-semibold text-foreground">{{
                            fechaMinima
                        }}</strong>
                        ({{ anticipacion.dias }} días de anticipación). Puedes
                        guardar el borrador con otras fechas.
                    </template>
                </p>

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

            <TarjetaSeccion
                titulo="Requerimientos por espacio"
                descripcion="Resumen de lo solicitado. Los requerimientos y el material se capturan dentro de cada espacio."
            >
                <p
                    v-if="resumenRequerimientos.length === 0"
                    class="px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    Elige un espacio para solicitar sus requerimientos.
                </p>
                <ul v-else class="divide-y divide-border text-sm">
                    <li
                        v-for="fila in resumenRequerimientos"
                        :key="fila.clave"
                        class="grid gap-1 p-4 md:grid-cols-3 md:gap-4 md:px-6"
                    >
                        <div>
                            <p class="font-medium">
                                Espacio {{ fila.bloque }}: {{ fila.espacio }}
                            </p>
                            <p v-if="fila.cuando" class="text-muted-foreground">
                                {{ fila.cuando }}
                            </p>
                            <p
                                v-if="fila.anticipacion"
                                class="mt-1 flex items-start gap-1.5 text-amber-700 dark:text-amber-400"
                            >
                                <TriangleAlert
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span
                                    >Cambia la fecha a partir del
                                    {{ fechaMinima }}: se requieren
                                    {{ anticipacion.dias }} días de anticipación
                                    para enviarla.</span
                                >
                            </p>
                        </div>
                        <div class="md:col-span-2">
                            <p v-if="fila.requerimientos.length">
                                {{ fila.requerimientos.join(', ') }}
                            </p>
                            <p v-else class="text-muted-foreground italic">
                                Sin requerimientos del catálogo.
                            </p>
                            <p
                                v-if="fila.material"
                                class="whitespace-pre-line text-muted-foreground"
                            >
                                Material: {{ fila.material }}
                            </p>
                        </div>
                    </li>
                </ul>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Información adicional">
                <div class="grid gap-4 p-4 md:grid-cols-2 md:p-6">
                    <CampoFormulario
                        id="costo_alumno"
                        etiqueta="Costo para el alumno (opcional)"
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
                        id="promocion_redes"
                        etiqueta="¿Promoción en redes sociales?"
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

            <AutorizacionesReserva :casillas="casillasAutorizacion" />

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
