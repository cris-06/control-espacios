import type { FrecuenciaRepeticion } from '@/types';

/**
 * Vista previa de «Esta reserva se repite». Replica las reglas de App\Services\RecurrenciaReserva
 * (que es quien realmente genera y valida las fechas en Laravel):
 *  - la cantidad son las ocasiones ADICIONALES después de la fecha original;
 *  - la fecha de fin, si la hay, tiene prioridad (incluida si coincide con el patrón);
 *  - cada fecha se calcula desde la original, sin desbordar el mes (31 ene → 28/29 feb → 31 mar;
 *    29 feb → 28 feb del año siguiente).
 */
export const MAX_REPETICIONES = 52;
export const MAX_DIAS_HASTA = 366;
export const MAX_BLOQUES = 100;

export const FRECUENCIAS: { valor: FrecuenciaRepeticion; etiqueta: string }[] =
    [
        { valor: 'diaria', etiqueta: 'Cada día' },
        { valor: 'semanal', etiqueta: 'Cada semana' },
        { valor: 'mensual', etiqueta: 'Cada mes' },
        { valor: 'anual', etiqueta: 'Cada año' },
    ];

const diasDelMes = (anio: number, mes: number) =>
    new Date(Date.UTC(anio, mes + 1, 0)).getUTCDate();

const aTexto = (d: Date) => d.toISOString().slice(0, 10);

const desdeTexto = (fecha: string): Date | null => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(fecha);

    return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null;
};

export const sumarFecha = (
    inicio: Date,
    frecuencia: FrecuenciaRepeticion,
    k: number,
): Date => {
    const anio = inicio.getUTCFullYear();
    const mes = inicio.getUTCMonth();
    const dia = inicio.getUTCDate();

    if (frecuencia === 'diaria' || frecuencia === 'semanal') {
        const dias = frecuencia === 'diaria' ? k : 7 * k;

        return new Date(Date.UTC(anio, mes, dia + dias));
    }

    // Mensual y anual: mismo día o el último del mes si no existe (sin desbordar).
    const meses = frecuencia === 'mensual' ? k : 12 * k;
    const destino = new Date(Date.UTC(anio, mes + meses, 1));
    const ultimo = diasDelMes(destino.getUTCFullYear(), destino.getUTCMonth());

    return new Date(
        Date.UTC(
            destino.getUTCFullYear(),
            destino.getUTCMonth(),
            Math.min(dia, ultimo),
        ),
    );
};

/**
 * Fechas de las repeticiones (sin la original). Devuelve como máximo MAX_REPETICIONES + 1 para
 * poder avisar que se superó el límite.
 */
export const fechasRepeticion = (
    original: string,
    frecuencia: FrecuenciaRepeticion,
    cantidad: number | null,
    hasta: string | null,
): string[] => {
    const inicio = desdeTexto(original);
    const fin = hasta ? desdeTexto(hasta) : null;

    if (!inicio || (!fin && (!cantidad || cantidad < 1))) {
        return [];
    }

    const fechas: string[] = [];

    for (let k = 1; k <= MAX_REPETICIONES + 1; k++) {
        if (!fin && k > (cantidad ?? 0)) {
            break;
        }

        const fecha = sumarFecha(inicio, frecuencia, k);

        if (fin && fecha > fin) {
            break;
        }

        fechas.push(aTexto(fecha));
    }

    return fechas;
};

export const sumarDias = (fecha: string, dias: number): string => {
    const d = desdeTexto(fecha);

    return d ? aTexto(new Date(d.getTime() + dias * 86_400_000)) : fecha;
};
