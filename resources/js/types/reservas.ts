export type RolDemo = 'superadmin' | 'admin' | 'coordinador' | 'docente';

export type Opcion = {
    id: number;
    nombre: string;
};

export type Reserva = {
    id: number;
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
