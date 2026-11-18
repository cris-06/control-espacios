const formatoFecha = new Intl.DateTimeFormat('es-MX', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const formatoFechaLarga = new Intl.DateTimeFormat('es-MX', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

// Se agrega la hora para que el navegador no interprete la fecha como UTC y muestre el día anterior.
export function aFecha(fecha: string): Date {
    return new Date(`${fecha}T00:00:00`);
}

export function formatearFecha(fecha: string): string {
    return formatoFecha.format(aFecha(fecha));
}

export function formatearFechaLarga(fecha: string): string {
    return formatoFechaLarga.format(aFecha(fecha));
}

export function sumarDias(fecha: string, dias: number): string {
    const resultado = aFecha(fecha);
    resultado.setDate(resultado.getDate() + dias);

    const mes = String(resultado.getMonth() + 1).padStart(2, '0');
    const dia = String(resultado.getDate()).padStart(2, '0');

    return `${resultado.getFullYear()}-${mes}-${dia}`;
}

export function aMinutos(hora: string): number {
    const [horas, minutos] = hora.split(':').map(Number);

    return horas * 60 + minutos;
}

// Quita los filtros vacíos para mantener limpia la URL.
export function sinVacios(
    filtros: Record<string, string | number | null | undefined>,
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filtros).filter(
            (entrada): entrada is [string, string | number] =>
                entrada[1] !== null &&
                entrada[1] !== undefined &&
                entrada[1] !== '',
        ),
    );
}

const formatoRelativo = new Intl.RelativeTimeFormat('es-MX', {
    numeric: 'auto',
});
const formatoFechaHora = new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

// "hace 5 minutos", "ayer"… y fecha completa pasada una semana.
export function haceCuanto(fechaIso: string): string {
    const fecha = new Date(fechaIso);
    const segundos = Math.round((fecha.getTime() - Date.now()) / 1000);
    const absolutos = Math.abs(segundos);

    if (absolutos < 60) {
        return 'hace un momento';
    }

    if (absolutos < 3600) {
        return formatoRelativo.format(Math.round(segundos / 60), 'minute');
    }

    if (absolutos < 86400) {
        return formatoRelativo.format(Math.round(segundos / 3600), 'hour');
    }

    if (absolutos < 7 * 86400) {
        return formatoRelativo.format(Math.round(segundos / 86400), 'day');
    }

    return formatoFechaHora.format(fecha);
}

export function formatearFechaHora(fechaIso: string): string {
    return formatoFechaHora.format(new Date(fechaIso));
}

// Folio que se muestra al usuario: el institucional del Formato 10 si existe; si no, el interno RES.
export function folioVisible(reserva: {
    folio: string | null;
    folio_institucional?: string | null;
}): string | null {
    return reserva.folio_institucional || reserva.folio;
}

/**
 * Etiqueta visible de una acción del flujo, igual en el listado y en el detalle. Solo cambia
 * el texto: la acción interna (aprobar, denegar, …) y sus permisos son los del servidor.
 */
export function etiquetaAccion(accion: {
    accion: string;
    etiqueta: string;
    destino?: number;
}): string {
    switch (accion.accion) {
        case 'aprobar':
            return 'Enviar a directivo';
        case 'autorizar':
            return 'Autorizar';
        case 'denegar':
            return 'Rechazar';
        case 'solicitar_correccion':
            return 'Mandar a corregir';
        case 'enviar':
            // estados_reserva.id 4 = pendiente de administración (directivo).
            return accion.destino === 4
                ? 'Enviar a directivo'
                : 'Enviar a coordinación';
        default:
            return accion.etiqueta;
    }
}
