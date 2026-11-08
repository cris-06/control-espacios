import type { NombreRol } from '@/types/reservas';

export type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type UsuarioFila = {
    id: number;
    name: string;
    correo: string;
    telefono: string | null;
    rol: { id: number; nombre: NombreRol; nivel: number };
    estatus: 'activo' | 'inactivo' | null;
    licenciaturas: string[];
    debe_cambiar_password: boolean;
    puede: {
        editar: boolean;
        cambiarEstatus: boolean;
        restablecerPassword: boolean;
    };
};

export type UsuarioFormulario = {
    id: number;
    nombre: string;
    apellido_paterno: string;
    apellido_materno: string | null;
    correo: string;
    telefono: string | null;
    rol_id: number;
    estatus: 'activo' | 'inactivo' | null;
    licenciaturas: number[];
    esYoMismo: boolean;
};

export type RolAsignable = {
    id: number;
    nombre: NombreRol;
    usaLicenciaturas: boolean;
};

export type LicenciaturaFila = {
    id: number;
    nombre: string;
    estatus: 'activa' | 'inactiva' | null;
    coordinadores_count?: number;
    docentes_count?: number;
};

export type PasswordTemporalFlash = {
    name: string;
    correo: string;
    password: string;
};
