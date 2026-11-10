import type { NombreRol } from '@/types/reservas';

export type User = {
    id: number;
    nombre: string;
    apellido_paterno: string;
    apellido_materno: string | null;
    correo: string;
    telefono: string | null;
    rol_id: number;
    estatus: 'activo' | 'inactivo' | null;
    debe_cambiar_password: boolean | null;
    /** Accessor: nombre + apellidos */
    name: string;
    /** Accessor: alias de `correo` */
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    fecha_registro: string | null;
    updated_at: string | null;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    rol: { nombre: NombreRol; nivel: number } | null;
    permisos: { administrar: boolean; verReportes: boolean };
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
