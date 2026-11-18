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
    | 'espacio_id'
    | 'espacio'
    | 'fecha'
    | 'hora_inicio'
    | 'hora_fin'
    | 'cantidad_alumnos'
> & {
    espacio_id: number | null;
    espacio: string | null;
    fecha: string | null;
    hora_inicio: string | null;
    hora_fin: string | null;
    cantidad_alumnos: number | null;
    // Solo en el listado de solicitudes: lo que el usuario puede hacer ahora (lo decide el servidor).
    acciones?: AccionesFila;
};

// Transición ofrecida en el listado (aprobar = «Enviar a directivo», denegar = «Rechazar»,
// solicitar_correccion = «Mandar a corregir»).
export type TransicionFila = {
    accion: 'aprobar' | 'autorizar' | 'denegar' | 'solicitar_correccion';
    etiqueta: string;
    requiereObservacion: boolean;
};

export type AccionesFila = {
    ver: boolean;
    editar: boolean;
    transiciones: TransicionFila[];
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
    docente_responsable_id: number | null;
    requerimientos: RequerimientoPedido[];
};

// Bloque mientras se edita en el formulario. Los <select> trabajan con texto y los
// <Input> no aceptan null: los opcionales vacíos van como '' (Laravel los vuelve null).
export type DetalleEditable = Omit<
    DetalleFormulario,
    | 'espacio_id'
    | 'cantidad_alumnos'
    | 'lugar_especifico'
    | 'docente_responsable_id'
> & {
    espacio_id: string | null;
    cantidad_alumnos: number | '';
    lugar_especifico: string;
    docente_responsable_id: string | null;
};

// Requerimiento solicitado para un bloque (tabla reserva_detalle_requerimientos).
export type RequerimientoPedido = {
    requerimiento_id: number;
    cantidad: number;
};

export type RequerimientoEspacio = Opcion & { cantidad_disponible: number };

export type EspacioOpcion = Opcion & {
    ubicacion: string | null;
    capacidad: number | null;
    minimo_alumnos: number | null;
    maximo_alumnos: number | null;
    estatus: string;
    requerimientos: RequerimientoEspacio[];
};

export type DocenteOpcion = Opcion & { licenciatura_ids: number[] };

// Respuesta de GET disponibilidad/verificar (folio solo si el usuario puede ver la reserva).
export type ChoqueDisponibilidad = {
    hora_inicio: string;
    hora_fin: string;
    estado: 'autorizada' | 'pendiente';
    folio: string | null;
};

export type VerificacionDisponibilidad = {
    situacion: Situacion;
    espacio: {
        estatus: string;
        capacidad: number | null;
        minimo_alumnos: number | null;
        maximo_alumnos: number | null;
    };
    choques: ChoqueDisponibilidad[];
};

export type ReservaFormulario = {
    id: number;
    folio: string | null;
    // Folio del Formato 10 ({SIGLAS}-{AAAAMMDD}-{ID}); null si la licenciatura no tenía siglas al crearla.
    folio_institucional?: string | null;
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
    // Fechas automáticas del servidor (solo lectura): elaboración al crear (ISO 8601)
    // y entrega al primer envío (AAAA-MM-DD, null mientras no se envíe).
    fecha_creacion: string | null;
    fecha_entrega_formato: string | null;
    estado_id: number;
    detalles: DetalleFormulario[];
};

export type OpcionesReserva = {
    licenciaturas: Opcion[];
    actividades: Opcion[];
    cuatrimestres: Opcion[];
    asignaturas: (Opcion & {
        licenciatura_id: number;
        cuatrimestre_id: number;
    })[];
    espacios: EspacioOpcion[];
    docentes: DocenteOpcion[];
};

export type AccionFlujo = {
    accion:
        | 'enviar'
        | 'aprobar'
        | 'solicitar_correccion'
        | 'autorizar'
        | 'denegar';
    etiqueta: string;
    destino: number;
    requiereObservacion: boolean;
};

// Requerimiento pedido que el catálogo actual ya no respalda (aviso previo del detalle).
export type AdvertenciaRequerimiento = {
    detalle_id: number;
    bloque: number;
    mensaje: string;
};

// Corrección o decisión vigente según el estado actual (autor, rol y fecha pueden faltar
// en registros antiguos sin historial).
export type ObservacionVigente = {
    tipo: 'correccion_solicitada' | 'autorizada' | 'denegada';
    comentario: string | null;
    autor: string | null;
    rol: string | null;
    fecha: string | null;
};

// Casilla de «Autorizaciones» del Formato 10, derivada del estado y del historial.
// Anticipación mínima del Formato 10, calculada en el servidor (fecha_minima: AAAA-MM-DD).
// ya_enviada: tras el primer envío solo deben cumplirla los bloques nuevos o modificados.
export type AnticipacionEnvio = {
    dias: number;
    fecha_minima: string;
    ya_enviada: boolean;
    // Solo informativo (lo calcula el servidor): edición del directivo, sin el mínimo de días.
    excepcion_directivo: boolean;
};

export type CasillaAutorizacion = {
    clave: 'docente' | 'coordinacion' | 'administracion';
    titulo: string;
    situacion: 'completada' | 'pendiente' | 'devuelta' | 'denegada';
    etiqueta: string;
    nombre: string | null;
    rol: string | null;
    fecha: string | null;
};

export type RegistroHistorial = {
    id: number;
    accion: string;
    comentario: string | null;
    usuario: string;
    fecha: string | null;
};

export type ReservaDetalleVista = DetalleFormulario & {
    espacio: {
        nombre: string;
        ubicacion: string | null;
        capacidad: number | null;
    };
    estado_detalle:
        | 'pendiente'
        | 'autorizada'
        | 'denegada'
        | 'cancelada'
        | null;
    docente_responsable: string | null;
    requerimientos_vista: { nombre: string; cantidad: number }[];
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
    // Valores habituales: 'info' | 'success' | 'warning' | 'error'. Se admite cualquier
    // texto (la unión anterior con `string` ya equivalía a `string`).
    tipo: string;
    leida: boolean;
    reserva_id: number | null;
    fecha: string | null;
};
