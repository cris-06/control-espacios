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
