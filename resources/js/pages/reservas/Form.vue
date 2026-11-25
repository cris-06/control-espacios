<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { FileText, Plus, Save, Send, UserPlus, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AutorizacionesReserva from '@/components/AutorizacionesReserva.vue';
import BloqueReserva from '@/components/BloqueReserva.vue';
import CampoFormulario from '@/components/CampoFormulario.vue';
import CampoSelect from '@/components/CampoSelect.vue';
import CuatrimestresGrupos from '@/components/CuatrimestresGrupos.vue';
import EncabezadoFormato from '@/components/EncabezadoFormato.vue';
import InputError from '@/components/InputError.vue';
import PaginaBase from '@/components/PaginaBase.vue';
import SelectorBuscable from '@/components/SelectorBuscable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    pausarConsultasDisponibilidad,
    reanudarConsultasDisponibilidad,
} from '@/composables/useDisponibilidadBloque';
import {
    FRECUENCIAS,
    MAX_BLOQUES,
    MAX_DIAS_HASTA,
    MAX_REPETICIONES,
    fechasRepeticion,
    sumarDias,
} from '@/lib/recurrencia';
import { aMinutos, folioVisible, formatearFecha } from '@/lib/reservas';
import { show, store, update } from '@/routes/reservas';
import { index as solicitudes } from '@/routes/solicitudes';
import type {
    AnticipacionEnvio,
    CasillaAutorizacion,
    CuatrimestreGrupos,
    DetalleEditable,
    FrecuenciaRepeticion,
    LicenciaturaAdicional,
    Opcion,
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
    espacioPreseleccionado?: number | null;
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

// Licenciatura adicional mientras se edita (los <select> trabajan con texto).
type LicenciaturaEditable = Omit<LicenciaturaAdicional, 'licenciatura_id'> & {
    licenciatura_id: string | null;
};

// Por omisión, el responsable (de la actividad y de un espacio nuevo) es quien llena la
// solicitud, si es docente o coordinador de alguna de sus licenciaturas.
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
    // --- Información general ---
    // folio, folio_institucional, fecha_creacion y fecha_entrega_formato no se envían: los fija
    // el servidor. tipo_formato ya no se captura (la columna conserva su valor).
    actividad_id: aTexto(props.reserva?.actividad_id),
    nombre_actividad: props.reserva?.nombre_actividad ?? '',
    // --- Objetivo y datos académicos ---
    objetivo: props.reserva?.objetivo ?? '',
    asignaturas: [...(props.reserva?.asignaturas ?? [])] as string[],
    docente_responsable_id: props.reserva
        ? aTexto(props.reserva.docente_responsable_id)
        : responsablePorOmision(),
    coordinador_id: aTexto(props.reserva?.coordinador_id),
    // --- Licenciaturas y cuatrimestres ---
    licenciatura_id: aTexto(
        props.reserva?.licenciatura_id ??
            (props.opciones.licenciaturas.length === 1
                ? props.opciones.licenciaturas[0].id
                : null),
    ),
    cuatrimestres: (props.reserva?.cuatrimestres ?? []).map(
        (c): CuatrimestreGrupos => ({ id: c.id, grupos: [...c.grupos] }),
    ),
    licenciaturas_adicionales: (
        props.reserva?.licenciaturas_adicionales ?? []
    ).map((l): LicenciaturaEditable => ({
        licenciatura_id: String(l.licenciatura_id),
        nombre: l.nombre,
        tipo: l.tipo,
        cuatrimestres: l.cuatrimestres.map((c) => ({
            id: c.id,
            grupos: [...c.grupos],
        })),
    })),
    // --- Esta reserva se repite ---
    repite: props.reserva?.repeticion != null,
    repeticion_frecuencia: (props.reserva?.repeticion?.frecuencia ??
        null) as FrecuenciaRepeticion | null,
    repeticion_cantidad: (props.reserva?.repeticion?.cantidad ?? '') as
        | number
        | '',
    repeticion_hasta: props.reserva?.repeticion?.hasta ?? '',
    // --- Alumnos, costos y textos generales ---
    cantidad_total_alumnos: (props.reserva?.cantidad_total_alumnos ?? '') as
        | number
        | '',
    costo_alumno: props.reserva?.costo_alumno ?? '',
    promocion_redes: props.reserva?.promocion_redes ?? 'no',
    descripcion_actividad: props.reserva?.descripcion_actividad ?? '',
    observaciones: props.reserva?.observaciones ?? '',
    // Solo la usa el servidor en la edición del directivo (historial «editada_admin»).
    motivo_edicion: '',
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

// Una solicitud nueva empieza con un espacio (o con el elegido en Disponibilidad).
if (props.reserva === null) {
    const preseleccionado =
        props.espacioPreseleccionado != null &&
        props.opciones.espacios.some(
            (espacio) =>
                espacio.id === props.espacioPreseleccionado &&
                espacio.estatus === 'disponible',
        )
            ? String(props.espacioPreseleccionado)
            : null;

    form.detalles.push({ ...detalleVacio(), espacio_id: preseleccionado });
}
// Si se llega aquí tras un «Guardar y enviar» que falló, el servidor redirige con los errores:
// se cargan en el formulario para mostrarlos debajo de cada campo.
form.setError(page.props.errors as Record<string, string>);

// Al cambiar un campo que tenía un error del servidor, ese error se quita: así no queda un
// mensaje viejo y vuelve a verse el aviso en vivo (p. ej. el mínimo y máximo de alumnos).
// El servidor vuelve a validar todo al guardar.
watch(
    () => form.nombre_actividad,
    () => form.clearErrors('nombre_actividad'),
);
watch(
    () => form.objetivo,
    () => form.clearErrors('objetivo'),
);
watch(
    () => form.cantidad_total_alumnos,
    () => form.clearErrors('cantidad_total_alumnos'),
);
watch(
    () => form.detalles.map((d) => d.cantidad_alumnos),
    (nuevos, anteriores) =>
        nuevos.forEach((cantidad, i) => {
            if (cantidad !== anteriores[i]) {
                form.clearErrors(
                    `detalles.${i}.cantidad_alumnos` as keyof typeof form.errors,
                );
            }
        }),
);

// --- Responsables de la actividad (solo usuarios de la licenciatura elegida) -----------------
const deLicenciatura = (lista: OpcionesReserva['docentes']): Opcion[] =>
    lista
        .filter(
            (u) =>
                form.licenciatura_id !== null &&
                u.licenciatura_ids.includes(Number(form.licenciatura_id)),
        )
        .map((u) => ({ id: u.id, nombre: u.nombre }));
const opcionesDocentes = computed(() =>
    deLicenciatura(props.opciones.docentes),
);
const opcionesCoordinadores = computed(() =>
    deLicenciatura(props.opciones.coordinadores),
);

// Si quien llena la solicitud coordina la licenciatura, el sistema lo asigna a él como
// coordinador (ReservaWorkflowService::asignarCoordinador); en la edición del directivo el
// coordinador ya revisó la solicitud y no se cambia. En ambos casos se muestra sin editar.
const usuarioCoordinaLicenciatura = computed(() =>
    opcionesCoordinadores.value.some((c) => c.id === page.props.auth.user.id),
);
const coordinadorFijo = computed(
    () =>
        props.anticipacion.excepcion_directivo ||
        usuarioCoordinaLicenciatura.value,
);
const nombreCoordinadorFijo = computed(() => {
    if (usuarioCoordinaLicenciatura.value) {
        return page.props.auth.user.name;
    }

    return (
        opcionesCoordinadores.value.find(
            (c) => String(c.id) === form.coordinador_id,
        )?.nombre ?? 'Asignado por el sistema'
    );
});

// Si cambia la licenciatura, se quitan los responsables que no pertenecen a ella.
watch(
    () => form.licenciatura_id,
    () => {
        if (
            form.docente_responsable_id !== null &&
            !opcionesDocentes.value.some(
                (d) => String(d.id) === form.docente_responsable_id,
            )
        ) {
            form.docente_responsable_id = null;
        }

        if (
            form.coordinador_id !== null &&
            !opcionesCoordinadores.value.some(
                (c) => String(c.id) === form.coordinador_id,
            )
        ) {
            form.coordinador_id = null;
        }
    },
);

// --- Licenciaturas participantes e invitadas -----------------------------------------------
// La principal (form.licenciatura_id) sigue determinando coordinador, permisos, folio y flujo.
// Las adicionales son solo informativas: no conceden permisos ni intervienen en la aprobación.
// Una licenciatura aparece a lo más una vez (Laravel lo vuelve a comprobar).
let siguienteClaveLicenciatura = 0;
const clavesLicenciaturas = ref(
    form.licenciaturas_adicionales.map(() => siguienteClaveLicenciatura++),
);
const usadas = (excepto: number) =>
    new Set(
        [
            form.licenciatura_id,
            ...form.licenciaturas_adicionales
                .filter((_, i) => i !== excepto)
                .map((l) => l.licenciatura_id),
        ].filter((id): id is string => id !== null),
    );
// Participantes: las licenciaturas del usuario (más la ya registrada, si era de otro editor).
const opcionesParticipante = (i: number): Opcion[] => {
    const libres = props.opciones.licenciaturas.filter(
        (l) => !usadas(i).has(String(l.id)),
    );
    const actual = form.licenciaturas_adicionales[i];

    return actual.licenciatura_id !== null &&
        !libres.some((l) => String(l.id) === actual.licenciatura_id)
        ? [
              {
                  id: Number(actual.licenciatura_id),
                  nombre: actual.nombre ?? '',
              },
              ...libres,
          ]
        : libres;
};
// Invitadas: cualquier licenciatura activa que no sea del usuario.
const propias = computed(
    () => new Set(props.opciones.licenciaturas.map((l) => String(l.id))),
);
const opcionesInvitada = (i: number): Opcion[] =>
    props.opciones.licenciaturas_invitables.filter(
        (l) => !usadas(i).has(String(l.id)) && !propias.value.has(String(l.id)),
    );
const hayParticipantesDisponibles = computed(
    () =>
        props.opciones.licenciaturas.filter(
            (l) => !usadas(-1).has(String(l.id)),
        ).length > 0,
);
const hayInvitablesDisponibles = computed(
    () =>
        props.opciones.licenciaturas_invitables.filter(
            (l) =>
                !usadas(-1).has(String(l.id)) &&
                !propias.value.has(String(l.id)),
        ).length > 0,
);
const agregarLicenciatura = (tipo: 'participante' | 'invitada') => {
    form.licenciaturas_adicionales.push({
        licenciatura_id: null,
        tipo,
        cuatrimestres: [],
    });
    clavesLicenciaturas.value.push(siguienteClaveLicenciatura++);
};
const quitarLicenciatura = (i: number) => {
    form.licenciaturas_adicionales.splice(i, 1);
    clavesLicenciaturas.value.splice(i, 1);
};
const errorLicenciatura = (i: number, campo: string) =>
    (form.errors as Record<string, string | undefined>)[
        `licenciaturas_adicionales.${i}.${campo}`
    ] ??
    Object.entries(form.errors as Record<string, string>).find(([clave]) =>
        clave.startsWith(`licenciaturas_adicionales.${i}.${campo}.`),
    )?.[1];

// --- Esta reserva se repite ----------------------------------------------------------------
const bloquesCapturados = computed(() =>
    form.detalles.filter((d) => /^\d{4}-\d{2}-\d{2}$/.test(d.fecha)),
);
const primeraFecha = computed(
    () =>
        bloquesCapturados.value
            .map((d) => d.fecha)
            .sort()
            .at(0) ?? null,
);
// Vista previa: cada bloque con sus fechas (la original y las repeticiones).
const vistaPrevia = computed(() => {
    if (!form.repite || !form.repeticion_frecuencia) {
        return null;
    }

    const cantidad =
        form.repeticion_cantidad === '' ? null : form.repeticion_cantidad;
    const hasta = form.repeticion_hasta || null;
    const bloques = form.detalles
        .map((d, i) => ({ d, i }))
        .filter(({ d }) => /^\d{4}-\d{2}-\d{2}$/.test(d.fecha))
        .map(({ d, i }) => ({
            numero: i + 1,
            espacio:
                props.opciones.espacios.find(
                    (e) => String(e.id) === d.espacio_id,
                )?.nombre ?? 'Espacio sin elegir',
            horario:
                d.hora_inicio && d.hora_fin
                    ? `${d.hora_inicio} – ${d.hora_fin}`
                    : '',
            fechas: [
                d.fecha,
                ...fechasRepeticion(
                    d.fecha,
                    form.repeticion_frecuencia as FrecuenciaRepeticion,
                    cantidad,
                    hasta,
                ),
            ],
        }));
    const excedido = bloques.some(
        (b) => b.fechas.length - 1 > MAX_REPETICIONES,
    );
    const total = bloques.reduce((n, b) => n + b.fechas.length, 0);

    return { bloques, total, excedido };
});
const avisoRepeticion = computed(() => {
    const v = vistaPrevia.value;

    if (!v || form.detalles.length === 0) {
        return form.repite && form.detalles.length === 0
            ? 'Agrega al menos un espacio con fecha para repetir la reserva.'
            : null;
    }

    if (form.repeticion_hasta && primeraFecha.value) {
        if (form.repeticion_hasta <= primeraFecha.value) {
            return 'La fecha de fin debe ser posterior a la primera fecha de la actividad.';
        }

        if (
            form.repeticion_hasta >
            sumarDias(primeraFecha.value, MAX_DIAS_HASTA)
        ) {
            return 'La fecha de fin puede ser a lo más un año después de la primera fecha.';
        }
    }

    if (v.excedido) {
        return `Habría más de ${MAX_REPETICIONES} repeticiones por espacio. Elige una fecha de fin más cercana o una frecuencia menor.`;
    }

    if (v.total > MAX_BLOQUES) {
        return `Se generarían ${v.total} actividades; el máximo por solicitud es ${MAX_BLOQUES}.`;
    }

    return null;
});
const errorRepeticion = (campo: string) =>
    (form.errors as Record<string, string | undefined>)[`repeticion_${campo}`];

// --- Asignaturas (varias, escritas a mano; el catálogo solo sugiere) ------------------------
const marcado = (id: number) => form.cuatrimestres.some((c) => c.id === id);
const sugerenciasAsignaturas = computed(() =>
    props.opciones.asignaturas
        .filter(
            (a) =>
                String(a.licenciatura_id) === form.licenciatura_id &&
                (form.cuatrimestres.length === 0 ||
                    marcado(a.cuatrimestre_id)) &&
                !form.asignaturas.some(
                    (n) => n.toLowerCase() === a.nombre.toLowerCase(),
                ),
        )
        .map((a) => a.nombre),
);
const nuevaAsignatura = ref('');
const agregarAsignatura = () => {
    const nombre = nuevaAsignatura.value.trim().replace(/\s+/g, ' ');

    if (
        nombre !== '' &&
        form.asignaturas.length < 10 &&
        !form.asignaturas.some((n) => n.toLowerCase() === nombre.toLowerCase())
    ) {
        form.asignaturas.push(nombre);
    }

    nuevaAsignatura.value = '';
};
const quitarAsignatura = (i: number) => form.asignaturas.splice(i, 1);
const errorAsignaturas = computed(
    () =>
        form.errors.asignaturas ??
        Object.entries(form.errors).find(([clave]) =>
            clave.startsWith('asignaturas.'),
        )?.[1],
);
// Cantidad de cuatrimestres de cada licenciatura (la configura el superadministrador).
const cuatrimestresDe = (id: string | number | null | undefined) =>
    id === null || id === undefined || id === ''
        ? undefined
        : props.opciones.cuatrimestres_por_licenciatura[Number(id)];

const errorCuatrimestres = computed(
    () =>
        form.errors.cuatrimestres ??
        Object.entries(form.errors).find(([clave]) =>
            clave.startsWith('cuatrimestres.'),
        )?.[1],
);

// --- Errores ------------------------------------------------------------------------------
// Fallas del envío que devuelve el servidor (reserva.0, reserva.1, …): se listan arriba; las
// que corresponden a un campo también aparecen debajo de ese campo.
const erroresEnvio = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(
            ([clave]) => clave === 'transicion' || clave.startsWith('reserva.'),
        )
        .map(([, mensaje]) => mensaje),
);

// Errores de campos al guardar, mostrados también al principio del formulario.
// Los mensajes del flujo de envío ya se muestran en erroresEnvio.
const erroresFormulario = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(
            ([clave]) =>
                clave !== 'transicion' && !clave.startsWith('reserva.'),
        )
        .map(([campo, mensaje]) => ({ campo, mensaje })),
);

const errorDetalle = (i: number, campo: string) =>
    (form.errors as Record<string, string | undefined>)[
        `detalles.${i}.${campo}`
    ];

// --- Espacios -----------------------------------------------------------------------------
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
const avisoAnticipacion = (d: DetalleEditable): string | null => {
    if (
        d.fecha === '' ||
        d.fecha >= props.anticipacion.fecha_minima ||
        !debeCumplirAnticipacion(d)
    ) {
        return null;
    }

    return props.anticipacion.excepcion_directivo
        ? `La fecha ya pasó: elige una a partir del ${fechaMinima.value}.`
        : `Para enviarla, cambia la fecha a partir del ${fechaMinima.value} (${props.anticipacion.dias} días de anticipación).`;
};

// --- Guardar ------------------------------------------------------------------------------
// Mientras se esperan las consultas de disponibilidad ya enviadas (antes de que form.processing
// se active), los botones quedan deshabilitados para no guardar dos veces.
const preparando = ref(false);
const ocupado = computed(() => form.processing || preparando.value);

const aNumero = (valor: string | null) =>
    valor === null ? null : Number(valor);

// Espacio agregado y no tocado: sin espacio, fecha, horario, alumnos ni textos.
const espacioSinCapturar = (d: DetalleEditable) =>
    d.id === null &&
    d.espacio_id === null &&
    d.fecha === '' &&
    d.hora_inicio === '' &&
    d.hora_fin === '' &&
    d.cantidad_alumnos === '' &&
    d.lugar_especifico.trim() === '' &&
    !d.descripcion_actividad?.trim() &&
    !d.material_requerido?.trim() &&
    !d.observaciones?.trim() &&
    d.requerimientos.length === 0;

const guardar = async (enviar = false) => {
    if (ocupado.value) {
        return;
    }

    // Sin consultas de disponibilidad durante el guardado: si una llega al servidor entre el
    // guardado y la recarga, se lleva los errores de validación (ver useDisponibilidadBloque).
    preparando.value = true;

    try {
        await pausarConsultasDisponibilidad();
    } finally {
        preparando.value = false;
    }

    const opciones = {
        preserveScroll: true,
        onError: () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        onFinish: () => reanudarConsultasDisponibilidad(),
    };

    // Una asignatura escrita pero sin «Agregar» también se guarda.
    agregarAsignatura();
    form.enviar = enviar;
    form.transform((datos) => ({
        ...datos,
        docente_responsable_id: aNumero(datos.docente_responsable_id),
        // Si el sistema fija el coordinador, no se envía: lo decide el servidor.
        coordinador_id: coordinadorFijo.value
            ? null
            : aNumero(datos.coordinador_id),
        cantidad_total_alumnos:
            datos.cantidad_total_alumnos === ''
                ? null
                : datos.cantidad_total_alumnos,
        licenciaturas_adicionales: datos.licenciaturas_adicionales.map((l) => ({
            licenciatura_id: aNumero(l.licenciatura_id),
            tipo: l.tipo,
            cuatrimestres: l.cuatrimestres,
        })),
        // Sin repetición, no se envía configuración; con fecha de fin, esta tiene prioridad.
        repeticion_frecuencia: datos.repite
            ? datos.repeticion_frecuencia
            : null,
        repeticion_cantidad:
            datos.repite &&
            !datos.repeticion_hasta &&
            datos.repeticion_cantidad !== ''
                ? datos.repeticion_cantidad
                : null,
        repeticion_hasta:
            datos.repite && datos.repeticion_hasta
                ? datos.repeticion_hasta
                : null,
        // Un espacio nuevo que sigue totalmente vacío no se envía: así se puede guardar el
        // borrador solo con los datos generales, como antes (se exigen al enviar a administración).
        detalles: datos.detalles
            .filter((d) => !espacioSinCapturar(d))
            // fechas_repeticion solo se muestra: las fechas las genera el servidor.
            .map(({ fechas_repeticion: _fechas, ...d }) => ({
                ...d,
                espacio_id: aNumero(d.espacio_id),
                docente_responsable_id: aNumero(d.docente_responsable_id),
            })),
    }));

    if (props.reserva) {
        form.put(update.url(props.reserva.id), opciones);
    } else {
        form.post(store.url(), opciones);
    }
};

// Nombre de la licenciatura actual (edición del directivo, donde se muestra sin poder cambiarla).
const licenciaturaActual = computed(
    () =>
        props.opciones.licenciaturas.find(
            (l) => String(l.id) === form.licenciatura_id,
        )?.nombre ?? '',
);

// «Guardar como borrador» mientras la solicitud está en manos de quien la llena (nueva, borrador
// o corrección: justo cuando puede enviarla); al revisar o autorizar, «Guardar cambios».
const etiquetaGuardar = computed(() =>
    !esEdicion.value || props.envio !== null
        ? 'Guardar como borrador'
        : 'Guardar cambios',
);

const textarea =
    'min-h-28 w-full rounded-md border border-input bg-background px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const select =
    'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const tituloSeccion = 'text-xl font-semibold text-rojo';
</script>

<template>
    <Head :title="titulo" />

    <PaginaBase
        :titulo="titulo"
        :descripcion="
            esEdicion
                ? 'Los cambios se guardan sin cambiar el estado de la reserva.'
                : 'Formato 10 de actividades o eventos. Se guardará como borrador hasta que la envíes.'
        "
    >
        <!-- Resumen visible de los errores de validación al guardar -->
        <div
            v-if="erroresFormulario.length > 0"
            role="alert"
            class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"
        >
            <p class="font-semibold">
                No se pudo guardar la reserva. Revisa lo siguiente:
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li v-for="error in erroresFormulario" :key="error.campo">
                    {{ error.mensaje }}
                </li>
            </ul>
        </div>

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

        <div
            v-if="erroresEnvio.length"
            role="alert"
            class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"
        >
            <p class="font-medium">
                No se pudo enviar. Corrige lo siguiente (cada campo marcado
                tiene su mensaje):
            </p>
            <ul class="mt-2 list-disc pl-5">
                <li v-for="(mensaje, i) in erroresEnvio" :key="i">
                    {{ mensaje }}
                </li>
            </ul>
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

            <div
                class="grid gap-10 rounded-xl border border-border bg-card p-4 shadow-sm sm:p-6 lg:p-8"
            >
                <p class="text-center text-sm text-muted-foreground">
                    Primero captura la información general y después agrega uno
                    o varios espacios.
                    <template v-if="anticipacion.excepcion_directivo">
                        Como directivo puedes cambiar fechas y horarios aunque
                        falten menos de {{ anticipacion.dias }} días, a partir
                        de hoy (<strong class="text-foreground">{{
                            fechaMinima
                        }}</strong
                        >).
                    </template>
                    <template v-else>
                        Para enviarla, cada espacio debe ser a partir del
                        <strong class="text-foreground">{{
                            fechaMinima
                        }}</strong>
                        ({{ anticipacion.dias }} días naturales de
                        anticipación).
                    </template>
                </p>

                <!-- 1. Información general -->
                <section class="grid gap-4" aria-labelledby="sec-general">
                    <h2 id="sec-general" :class="tituloSeccion">
                        Información general
                    </h2>
                    <!-- La fecha de entrega (como el folio y la fecha de elaboración) solo se muestra en
                         el encabezado institucional de arriba: la asigna el sistema en el primer envío. -->
                    <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                        <CampoFormulario
                            id="actividad_id"
                            etiqueta="Tipo de actividad o evento"
                            :error="form.errors.actividad_id"
                        >
                            <select
                                id="actividad_id"
                                v-model="form.actividad_id"
                                :class="select"
                            >
                                <option :value="null">Seleccione</option>
                                <option
                                    v-for="a in opciones.actividades"
                                    :key="a.id"
                                    :value="String(a.id)"
                                >
                                    {{ a.nombre }}
                                </option>
                            </select>
                        </CampoFormulario>
                        <CampoFormulario
                            id="nombre_actividad"
                            etiqueta="Nombre de la actividad *"
                            :error="form.errors.nombre_actividad"
                            class="md:col-span-2"
                        >
                            <Input
                                id="nombre_actividad"
                                v-model="form.nombre_actividad"
                                class="h-10"
                                maxlength="255"
                            />
                        </CampoFormulario>
                    </div>
                </section>

                <!-- 2. Espacios y horarios -->
                <section class="grid gap-5" aria-labelledby="sec-espacios">
                    <div class="text-center">
                        <h2
                            id="sec-espacios"
                            class="text-2xl font-bold text-rojo sm:text-3xl"
                        >
                            Espacios y horarios
                        </h2>
                        <p
                            v-if="anticipacion.ya_enviada"
                            class="mt-1 text-sm text-muted-foreground"
                        >
                            Las fechas que ya enviaste se conservan. Un espacio
                            nuevo o con otra fecha u horario debe ser a partir
                            del {{ fechaMinima }}.
                        </p>
                    </div>

                    <p
                        v-if="form.detalles.length === 0"
                        class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground"
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
                        :fecha-minima="
                            debeCumplirAnticipacion(form.detalles[i])
                                ? anticipacion.fecha_minima
                                : null
                        "
                        :aviso-anticipacion="
                            avisoAnticipacion(form.detalles[i])
                        "
                        :error="(campo: string) => errorDetalle(i, campo)"
                        @duplicar="duplicarDetalle(i)"
                        @quitar="quitarDetalle(i)"
                    />
                    <InputError :message="form.errors.detalles" />

                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            @click="agregarDetalle"
                        >
                            <Plus class="size-4" />
                            Agregar otro espacio
                        </Button>
                    </div>
                </section>

                <!-- 2b. Esta reserva se repite -->
                <section
                    class="grid gap-5 rounded-xl border border-border bg-muted/40 p-4 sm:p-6"
                    aria-labelledby="sec-repite"
                >
                    <label
                        id="sec-repite"
                        class="flex w-fit cursor-pointer items-center gap-3 text-lg font-semibold text-rojo"
                    >
                        <input
                            id="repite"
                            v-model="form.repite"
                            type="checkbox"
                            class="size-5 accent-rojo"
                        />
                        Esta reserva se repite
                    </label>

                    <template v-if="form.repite">
                        <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                            <CampoFormulario
                                id="repeticion_frecuencia"
                                etiqueta="Frecuencia"
                                :error="errorRepeticion('frecuencia')"
                            >
                                <select
                                    id="repeticion_frecuencia"
                                    v-model="form.repeticion_frecuencia"
                                    :class="select"
                                >
                                    <option :value="null" disabled>
                                        Seleccione
                                    </option>
                                    <option
                                        v-for="f in FRECUENCIAS"
                                        :key="f.valor"
                                        :value="f.valor"
                                    >
                                        {{ f.etiqueta }}
                                    </option>
                                </select>
                            </CampoFormulario>
                            <CampoFormulario
                                id="repeticion_cantidad"
                                etiqueta="Número de repeticiones"
                                :error="errorRepeticion('cantidad')"
                            >
                                <Input
                                    id="repeticion_cantidad"
                                    v-model.number="form.repeticion_cantidad"
                                    class="h-10"
                                    type="number"
                                    min="1"
                                    :max="MAX_REPETICIONES"
                                    step="1"
                                    :disabled="!!form.repeticion_hasta"
                                    :aria-describedby="
                                        form.repeticion_hasta
                                            ? 'nota-cantidad'
                                            : undefined
                                    "
                                />
                                <p
                                    v-if="form.repeticion_hasta"
                                    id="nota-cantidad"
                                    class="text-xs text-amber-700"
                                >
                                    Se usa la fecha de fin; el número de
                                    repeticiones no se toma en cuenta.
                                </p>
                                <p v-else class="text-xs text-muted-foreground">
                                    Veces adicionales después de la fecha
                                    original (máximo {{ MAX_REPETICIONES }}).
                                </p>
                            </CampoFormulario>
                            <CampoFormulario
                                id="repeticion_hasta"
                                etiqueta="Fecha de fin de la repetición (opcional)"
                                :error="errorRepeticion('hasta')"
                                class="md:col-span-2"
                            >
                                <Input
                                    id="repeticion_hasta"
                                    v-model="form.repeticion_hasta"
                                    class="h-10"
                                    type="date"
                                    :min="primeraFecha ?? undefined"
                                    :max="
                                        primeraFecha
                                            ? sumarDias(
                                                  primeraFecha,
                                                  MAX_DIAS_HASTA,
                                              )
                                            : undefined
                                    "
                                />
                            </CampoFormulario>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Si indicas fecha de fin, se generarán fechas hasta
                            ese día (incluido si coincide). Si no, se usará el
                            número de repeticiones: por ejemplo, el 1 de
                            diciembre cada semana con 4 repeticiones son 5
                            fechas (1, 8, 15, 22 y 29 de diciembre). Cada
                            repetición conserva el espacio, horario,
                            responsable, alumnos y requerimientos de su espacio,
                            y pasa por las mismas validaciones al enviar y
                            autorizar.
                        </p>

                        <p
                            v-if="avisoRepeticion"
                            role="alert"
                            class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"
                        >
                            {{ avisoRepeticion }}
                        </p>
                        <div
                            v-else-if="
                                vistaPrevia && vistaPrevia.bloques.length
                            "
                            class="grid gap-3 rounded-lg border border-border bg-background p-4"
                            aria-live="polite"
                        >
                            <p class="font-semibold">
                                Vista previa: se registrarán
                                {{ vistaPrevia.total }}
                                {{
                                    vistaPrevia.total === 1
                                        ? 'actividad'
                                        : 'actividades'
                                }}
                            </p>
                            <div
                                v-for="b in vistaPrevia.bloques"
                                :key="b.numero"
                                class="grid gap-1.5"
                            >
                                <p class="text-sm font-medium">
                                    Espacio {{ b.numero }} · {{ b.espacio }}
                                    <span
                                        v-if="b.horario"
                                        class="font-normal text-muted-foreground"
                                        >· {{ b.horario }}
                                    </span>
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >· {{ b.fechas.length }} fechas</span
                                    >
                                </p>
                                <ul class="flex flex-wrap gap-1.5">
                                    <li
                                        v-for="(f, k) in b.fechas"
                                        :key="f"
                                        class="rounded-full border px-2.5 py-0.5 text-xs tabular-nums"
                                        :class="
                                            k === 0
                                                ? 'border-rojo bg-rojo text-rojo-foreground'
                                                : 'border-border bg-muted'
                                        "
                                        :title="
                                            k === 0
                                                ? 'Fecha original'
                                                : `Repetición ${k}`
                                        "
                                    >
                                        {{ formatearFecha(f) }}
                                    </li>
                                </ul>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                La disponibilidad de cada fecha se valida en
                                Laravel al guardar (avisos), al enviar y al
                                autorizar.
                            </p>
                        </div>
                    </template>
                </section>

                <!-- 3. Objetivo y datos académicos -->
                <section class="grid gap-5" aria-labelledby="sec-academicos">
                    <h2 id="sec-academicos" :class="tituloSeccion">
                        Objetivo y datos académicos
                    </h2>
                    <CampoFormulario
                        id="objetivo"
                        etiqueta="Objetivo *"
                        :error="form.errors.objetivo"
                    >
                        <textarea
                            id="objetivo"
                            v-model="form.objetivo"
                            :class="textarea"
                            maxlength="5000"
                        />
                    </CampoFormulario>
                    <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                        <CampoFormulario
                            id="asignatura_nueva"
                            etiqueta="Asignatura vinculada"
                            :error="errorAsignaturas"
                        >
                            <div class="flex gap-2">
                                <Input
                                    id="asignatura_nueva"
                                    v-model="nuevaAsignatura"
                                    class="h-10"
                                    list="sugerencias_asignaturas"
                                    maxlength="150"
                                    placeholder="Escribe el nombre de la asignatura"
                                    :disabled="form.asignaturas.length >= 10"
                                    @keydown.enter.prevent="agregarAsignatura"
                                />
                                <datalist id="sugerencias_asignaturas">
                                    <option
                                        v-for="nombre in sugerenciasAsignaturas"
                                        :key="nombre"
                                        :value="nombre"
                                    />
                                </datalist>
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="h-10"
                                    :disabled="
                                        nuevaAsignatura.trim() === '' ||
                                        form.asignaturas.length >= 10
                                    "
                                    @click="agregarAsignatura"
                                >
                                    <Plus class="size-4" />
                                    Agregar
                                </Button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Opcional; puedes agregar varias (hasta 10).
                            </p>
                            <ul
                                v-if="form.asignaturas.length"
                                class="flex flex-wrap gap-2"
                            >
                                <li
                                    v-for="(nombre, i) in form.asignaturas"
                                    :key="nombre"
                                    class="flex items-center gap-1 rounded-full bg-secondary py-1 pr-1 pl-3 text-sm text-secondary-foreground"
                                >
                                    {{ nombre }}
                                    <button
                                        type="button"
                                        class="rounded-full p-0.5 hover:bg-rojo/10"
                                        :aria-label="`Quitar ${nombre}`"
                                        @click="quitarAsignatura(i)"
                                    >
                                        <X class="size-3.5" />
                                    </button>
                                </li>
                            </ul>
                        </CampoFormulario>
                        <CampoFormulario
                            id="docente_responsable"
                            etiqueta="Docente responsable"
                            :error="form.errors.docente_responsable_id"
                        >
                            <SelectorBuscable
                                id="docente_responsable"
                                v-model="form.docente_responsable_id"
                                :opciones="opcionesDocentes"
                                sin-opciones="Primero elige la licenciatura"
                            />
                            <p class="text-xs text-muted-foreground">
                                Puedes escribir el nombre o seleccionarlo de la
                                lista.
                            </p>
                        </CampoFormulario>
                    </div>
                    <CampoFormulario
                        id="coordinador_responsable"
                        etiqueta="Coordinador responsable"
                        :error="form.errors.coordinador_id"
                    >
                        <template v-if="coordinadorFijo">
                            <p
                                id="coordinador_responsable"
                                class="flex min-h-10 items-center rounded-md border border-input bg-muted px-3 py-1 text-sm"
                                aria-readonly="true"
                            >
                                {{ nombreCoordinadorFijo }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    anticipacion.excepcion_directivo
                                        ? 'La coordinación ya revisó la solicitud; el directivo no cambia al coordinador.'
                                        : 'Coordinas esta licenciatura: el sistema te asigna como coordinador.'
                                }}
                            </p>
                        </template>
                        <template v-else>
                            <SelectorBuscable
                                id="coordinador_responsable"
                                v-model="form.coordinador_id"
                                :opciones="opcionesCoordinadores"
                                placeholder="Escribe o selecciona…"
                                sin-opciones="Primero elige la licenciatura"
                            />
                            <p class="text-xs text-muted-foreground">
                                Solo coordinadores de la licenciatura. Si no
                                eliges uno, el sistema asigna al coordinador de
                                la licenciatura.
                            </p>
                        </template>
                    </CampoFormulario>
                </section>

                <!-- 4. Licenciaturas y cuatrimestres: el diseño de dev (licenciatura y periodo
                     cuatrimestral con grupos), más licenciaturas participantes e invitadas. -->
                <section class="grid gap-4" aria-labelledby="sec-licenciaturas">
                    <h2 id="sec-licenciaturas" :class="tituloSeccion">
                        Licenciaturas y cuatrimestres
                    </h2>
                    <div class="grid gap-4 md:grid-cols-2">
                        <CampoFormulario
                            id="licenciatura_id"
                            etiqueta="Licenciatura"
                            :error="form.errors.licenciatura_id"
                        >
                            <!-- Edición del directivo: la licenciatura es de solo lectura (Laravel también
                                 rechaza cualquier cambio; ver ReservaRequest y ReservaService). -->
                            <template v-if="anticipacion.excepcion_directivo">
                                <p
                                    id="licenciatura_id"
                                    class="flex min-h-9 items-center rounded-md border border-input bg-muted px-3 py-1 text-sm"
                                    aria-readonly="true"
                                >
                                    {{ licenciaturaActual }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        'El directivo no puede cambiar la licenciatura de la solicitud.'
                                    }}
                                </p>
                            </template>
                            <CampoSelect
                                v-else
                                id="licenciatura_id"
                                v-model="form.licenciatura_id"
                                :opciones="opciones.licenciaturas"
                                vacio="Selecciona una licenciatura"
                            />
                        </CampoFormulario>
                        <CuatrimestresGrupos
                            v-model="form.cuatrimestres"
                            :cuatrimestres="opciones.cuatrimestres"
                            :limite="cuatrimestresDe(form.licenciatura_id)"
                            :error="errorCuatrimestres"
                            class="md:col-span-2"
                        />
                    </div>

                    <!-- Licenciaturas adicionales (solo informativas: no dan permisos). -->
                    <div
                        v-for="(l, i) in form.licenciaturas_adicionales"
                        :key="clavesLicenciaturas[i]"
                        class="grid gap-4 rounded-xl border p-4 md:grid-cols-2 md:p-5"
                        :class="
                            l.tipo === 'invitada'
                                ? 'border-rojo/30 bg-secondary/40'
                                : 'border-border bg-muted/40'
                        "
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 md:col-span-2"
                        >
                            <p class="flex items-center gap-2 font-semibold">
                                {{
                                    l.tipo === 'invitada'
                                        ? 'Licenciatura invitada'
                                        : 'Licenciatura participante'
                                }}
                                <span
                                    v-if="l.tipo === 'invitada'"
                                    class="rounded-full border border-rojo/30 bg-secondary px-2 py-0.5 text-xs font-semibold text-secondary-foreground"
                                    >Invitada</span
                                >
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="quitarLicenciatura(i)"
                            >
                                <X class="size-4" />
                                Quitar
                            </Button>
                        </div>
                        <CampoFormulario
                            :id="`licenciatura-adicional-${i}`"
                            etiqueta="Licenciatura"
                            :error="errorLicenciatura(i, 'licenciatura_id')"
                        >
                            <CampoSelect
                                :id="`licenciatura-adicional-${i}`"
                                v-model="l.licenciatura_id"
                                :opciones="
                                    l.tipo === 'invitada'
                                        ? opcionesInvitada(i)
                                        : opcionesParticipante(i)
                                "
                                vacio="Seleccione"
                            />
                        </CampoFormulario>
                        <CuatrimestresGrupos
                            v-model="l.cuatrimestres"
                            :cuatrimestres="opciones.cuatrimestres"
                            :limite="cuatrimestresDe(l.licenciatura_id)"
                            :error="errorLicenciatura(i, 'cuatrimestres')"
                            leyenda="Cuatrimestres y grupos de esta licenciatura (puedes marcar varios)"
                            class="md:col-span-2"
                        />
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Agrega una licenciatura con sus cuatrimestres por cada
                        grupo participante. Usa «Invitar licenciatura» para
                        incluir una licenciatura externa (que no está asignada a
                        tu usuario). Solo se registran como participantes: no
                        reciben permisos ni intervienen en la autorización, que
                        sigue a cargo de la licenciatura principal.
                    </p>
                    <InputError
                        :message="form.errors.licenciaturas_adicionales"
                    />
                    <div class="flex flex-wrap gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            class="h-auto min-h-9 max-w-full py-2 whitespace-normal"
                            :disabled="!hayParticipantesDisponibles"
                            :title="
                                hayParticipantesDisponibles
                                    ? undefined
                                    : 'No tienes otras licenciaturas asignadas.'
                            "
                            @click="agregarLicenciatura('participante')"
                        >
                            <Plus class="size-4" />
                            Agregar licenciatura y cuatrimestre
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            class="h-auto min-h-9 max-w-full py-2 whitespace-normal"
                            :disabled="!hayInvitablesDisponibles"
                            @click="agregarLicenciatura('invitada')"
                        >
                            <UserPlus class="size-4" />
                            Invitar licenciatura
                        </Button>
                    </div>
                </section>

                <!-- 5. Alumnos, costos y promoción -->
                <section class="grid gap-5" aria-labelledby="sec-alumnos">
                    <h2 id="sec-alumnos" class="sr-only">
                        Alumnos, costo y promoción
                    </h2>
                    <div class="grid gap-x-6 gap-y-5 md:grid-cols-2">
                        <CampoFormulario
                            id="cantidad_total_alumnos"
                            etiqueta="Cantidad total de alumnos"
                            :error="form.errors.cantidad_total_alumnos"
                        >
                            <Input
                                id="cantidad_total_alumnos"
                                v-model.number="form.cantidad_total_alumnos"
                                class="h-10"
                                type="number"
                                min="1"
                            />
                            <p class="text-xs text-muted-foreground">
                                Alumnos distintos de toda la actividad. No es la
                                suma de los espacios: un mismo grupo puede usar
                                varios.
                            </p>
                        </CampoFormulario>
                        <CampoFormulario
                            id="costo_alumno"
                            etiqueta="Costo por alumno"
                            :error="form.errors.costo_alumno"
                        >
                            <Input
                                id="costo_alumno"
                                v-model="form.costo_alumno"
                                class="h-10"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                            <p class="text-xs text-muted-foreground">
                                Usa 0 si la actividad es gratuita.
                            </p>
                        </CampoFormulario>
                        <CampoFormulario
                            id="promocion_redes"
                            etiqueta="Promoción en redes"
                            :error="form.errors.promocion_redes"
                        >
                            <select
                                id="promocion_redes"
                                v-model="form.promocion_redes"
                                :class="select"
                            >
                                <option value="no">No</option>
                                <option value="si">Sí</option>
                            </select>
                        </CampoFormulario>
                    </div>
                </section>

                <!-- 6. Descripción y observaciones -->
                <section class="grid gap-5" aria-labelledby="sec-descripcion">
                    <h2 id="sec-descripcion" class="sr-only">
                        Descripción y observaciones
                    </h2>
                    <CampoFormulario
                        id="descripcion_actividad"
                        etiqueta="Descripción de la actividad (incluir procedimientos, reseña de ponentes y detalles del desarrollo de la actividad de principio a fin)"
                        :error="form.errors.descripcion_actividad"
                    >
                        <textarea
                            id="descripcion_actividad"
                            v-model="form.descripcion_actividad"
                            :class="textarea"
                            rows="5"
                            maxlength="10000"
                        />
                    </CampoFormulario>
                    <CampoFormulario
                        id="observaciones"
                        etiqueta="Observaciones, anexos, jueces, invitados, etc."
                        :error="form.errors.observaciones"
                    >
                        <textarea
                            id="observaciones"
                            v-model="form.observaciones"
                            :class="textarea"
                            rows="4"
                            maxlength="5000"
                        />
                    </CampoFormulario>
                </section>

                <section
                    v-if="anticipacion.excepcion_directivo"
                    class="grid gap-3"
                    aria-labelledby="sec-motivo"
                >
                    <h2 id="sec-motivo" :class="tituloSeccion">
                        Motivo de la edición
                    </h2>
                    <CampoFormulario
                        id="motivo_edicion"
                        etiqueta="Motivo (opcional; se guarda en el historial con tu nombre, la fecha y el resumen de los cambios)"
                        :error="form.errors.motivo_edicion"
                    >
                        <textarea
                            id="motivo_edicion"
                            v-model="form.motivo_edicion"
                            maxlength="1000"
                            rows="3"
                            :class="textarea"
                            placeholder="Por ejemplo: se ajustó la fecha por disponibilidad del auditorio."
                        />
                    </CampoFormulario>
                </section>

                <div class="grid gap-4">
                    <p
                        class="flex items-start gap-2 rounded-lg border border-border bg-muted/50 px-3 py-2.5 text-sm text-muted-foreground"
                    >
                        <FileText class="mt-0.5 size-4 shrink-0" />
                        El borrador conserva los espacios seleccionados, pero no
                        los aparta. La disponibilidad se valida al enviar la
                        reserva y se confirma al autorizarla.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            :variant="envio ? 'outline' : 'default'"
                            :disabled="ocupado"
                        >
                            <Spinner v-if="form.processing && !form.enviar" />
                            <Save v-else class="size-4" />
                            {{ etiquetaGuardar }}
                        </Button>
                        <Button
                            v-if="envio"
                            type="button"
                            :title="envio"
                            :disabled="ocupado"
                            @click="guardar(true)"
                        >
                            <Spinner v-if="form.processing && form.enviar" />
                            <Send v-else class="size-4" />
                            Enviar reserva
                        </Button>
                        <Button variant="outline" as-child>
                            <Link
                                :href="
                                    reserva ? show(reserva.id) : solicitudes()
                                "
                                >Cancelar</Link
                            >
                        </Button>
                    </div>
                </div>
            </div>

            <AutorizacionesReserva :casillas="casillasAutorizacion" />
        </form>
    </PaginaBase>
</template>
