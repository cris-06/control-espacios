export type NombreRol = 'superadmin' | 'admin' | 'coordinador' | 'docente';

export type Opcion = {
    id: number;
    nombre: string;
};

export type Reserva = {
    // En filas por detalle (reportes, disponibilidad), id es el del detalle y reserva_id el de la reserva.
    id: number;
    reserva_id?: number | null;
    // false cuando el usuario no puede ver la reserva (solo se muestra «Reservado»).
    visible?: boolean;
    folio: string;
    actividad: string;
    docente: string;
    licenciatura: string;
    espacio_id: number;
    espacio: string;
    fecha: string;
    hora_inicio: string;
    hora_fin: string;
    cantidad_alumnos: number;
    estado_id: number;
    estado: string;
};

// Fila del listado real de solicitudes: los datos del primer detalle son nulos
// mientras la reserva no tenga detalles (borrador incompleto).
export type ReservaFila = Omit<
    Reserva,
    'espacio_id' | 'espacio' | 'fecha' | 'hora_inicio' | 'hora_fin' | 'cantidad_alumnos'
> & {
    espacio_id: number | null;
    espacio: string | null;
    fecha: string | null;
    hora_inicio: string | null;
    hora_fin: string | null;
    cantidad_alumnos: number | null;
};

export type Indicador = {
    titulo: string;
    valor: number;
    descripcion: string;
};

export type Situacion = 'libre' | 'pendiente' | 'ocupado' | 'fuera_de_servicio';

export type EspacioDisponibilidad = {
    id: number;
    nombre: string;
    ubicacion: string;
    capacidad: number;
    color_calendario: string;
    estatus: string;
    situacion: Situacion | null;
    bloques: Reserva[];
};

export type Total = {
    nombre: string;
    total: number;
};

// --- Reservas reales (Fase 4) ---

export type DetalleFormulario = {
    id: number | null;
    espacio_id: number | null;
    fecha: string;
    hora_inicio: string;
    hora_fin: string;
    cantidad_alumnos: number | null;
    lugar_especifico: string | null;
    descripcion_actividad: string | null;
    material_requerido: string | null;
    observaciones: string | null;
};

export type ReservaFormulario = {
    id: number;
    folio: string | null;
    licenciatura_id: number;
    actividad_id: number;
    nombre_actividad: string | null;
    objetivo: string;
    tipo_formato: 'normal' | 'ext' | null;
    cuatrimestre_id: number | null;
    asignatura_id: number | null;
    costo_alumno: string | null;
    promocion_redes: 'si' | 'no' | null;
    limite_min_usuarios: number | null;
    limite_max_usuarios: number | null;
    fecha_entrega_formato: string | null;
    estado_id: number;
    detalles: DetalleFormulario[];
};

export type OpcionesReserva = {
    licenciaturas: Opcion[];
    actividades: Opcion[];
    cuatrimestres: Opcion[];
    asignaturas: (Opcion & { licenciatura_id: number; cuatrimestre_id: number })[];
    espacios: (Opcion & {
        ubicacion: string | null;
        capacidad: number | null;
        estatus: string;
    })[];
};

export type AccionFlujo = {
    accion: 'enviar' | 'aprobar' | 'solicitar_correccion' | 'autorizar' | 'denegar';
    etiqueta: string;
    destino: number;
    requiereObservacion: boolean;
};

export type RegistroHistorial = {
    id: number;
    accion: string;
    comentario: string | null;
    usuario: string;
    fecha: string | null;
};

export type ReservaDetalleVista = DetalleFormulario & {
    espacio: { nombre: string; ubicacion: string | null; capacidad: number | null };
    estado_detalle: 'pendiente' | 'autorizada' | 'denegada' | 'cancelada' | null;
};

export type ReservaVista = Omit<ReservaFormulario, 'detalles'> & {
    estado: { id: number; nombre: string; descripcion: string | null };
    creador: string;
    coordinador: string | null;
    licenciatura: string;
    actividad: string;
    cuatrimestre: string | null;
    asignatura: string | null;
    observaciones_admin: string | null;
    detalles: ReservaDetalleVista[];
    historial: RegistroHistorial[];
};

// --- Notificaciones (Fase 5) ---

export type Notificacion = {
    id: number;
    titulo: string;
    mensaje: string;
    tipo: 'info' | 'success' | 'warning' | 'error' | string;
    leida: boolean;
    reserva_id: number | null;
    fecha: string | null;
};
