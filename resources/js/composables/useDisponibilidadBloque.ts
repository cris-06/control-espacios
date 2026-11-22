import { onBeforeUnmount, ref, watch } from 'vue';
import { verificar } from '@/routes/disponibilidad';
import type { VerificacionDisponibilidad } from '@/types';

type Bloque = {
    espacio_id: string | null;
    fecha: string;
    hora_inicio: string;
    hora_fin: string;
};

export type EstadoConsulta =
    | { tipo: 'incompleto' }
    | { tipo: 'cargando' }
    | { tipo: 'error'; mensaje: string }
    | { tipo: 'listo'; resultado: VerificacionDisponibilidad };

/*
 * Coordinación con el guardado del formulario.
 *
 * Laravel entrega los errores de validación (y los avisos) a UNA sola petición: la siguiente
 * al guardado rechazado. Si una consulta de disponibilidad llega al servidor entre el guardado
 * y la recarga del formulario, se lleva esos errores y el formulario vuelve sin mensajes.
 * Abortar la consulta en el navegador no basta: si ya salió, el servidor la atiende igual.
 *
 * Por eso, antes de guardar, `pausarConsultasDisponibilidad()` cancela las consultas programadas,
 * impide lanzar nuevas y ESPERA a que terminen las que ya están en el servidor; al terminar el
 * guardado, `reanudarConsultasDisponibilidad()` vuelve a comprobar cada bloque.
 */
let pausadas = false;
const enCurso = new Set<Promise<unknown>>();
const bloques = new Set<{ pausar: () => void; reanudar: () => void }>();

/** Tiempo máximo de espera por consultas ya enviadas antes de guardar de todos modos. */
const ESPERA_MAXIMA_MS = 5000;

export async function pausarConsultasDisponibilidad(): Promise<void> {
    pausadas = true;
    bloques.forEach((b) => b.pausar());

    if (enCurso.size === 0) {
        return;
    }

    let limite: ReturnType<typeof setTimeout> | undefined;

    await Promise.race([
        Promise.allSettled(enCurso),
        new Promise((resolver) => {
            limite = setTimeout(resolver, ESPERA_MAXIMA_MS);
        }),
    ]);
    clearTimeout(limite);
}

export function reanudarConsultasDisponibilidad(): void {
    if (!pausadas) {
        return;
    }

    pausadas = false;
    bloques.forEach((b) => b.reanudar());
}

/**
 * Consulta en vivo (GET disponibilidad/verificar) si el bloque está libre. Solo es
 * informativa: el servidor vuelve a comprobar todo al enviar y al autorizar.
 */
export function useDisponibilidadBloque(
    bloque: () => Bloque,
    reservaId: () => number | null,
    espera = 400,
) {
    const estado = ref<EstadoConsulta>({ tipo: 'incompleto' });
    let temporizador: ReturnType<typeof setTimeout> | undefined;
    // Solo se muestra la respuesta de la consulta más reciente. Las anteriores no se abortan
    // (si ya salieron, el servidor las atiende igual): terminan y su resultado se descarta,
    // y así el guardado puede esperarlas.
    let ultima = 0;
    // Hubo cambios (o una consulta programada) mientras las consultas estaban en pausa.
    let pendiente = false;
    let desmontado = false;

    const consultar = async (b: Bloque, id: number | null, numero: number) => {
        const vigente = () => !desmontado && numero === ultima;

        try {
            const respuesta = await fetch(
                verificar.url({
                    query: {
                        espacio_id: b.espacio_id,
                        fecha: b.fecha,
                        hora_inicio: b.hora_inicio,
                        hora_fin: b.hora_fin,
                        reserva_id: id ?? undefined,
                    },
                }),
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (!vigente()) {
                return;
            }

            if (respuesta.status === 422) {
                const cuerpo = (await respuesta.json()) as {
                    message?: string;
                };

                if (vigente()) {
                    estado.value = {
                        tipo: 'error',
                        mensaje:
                            cuerpo.message ?? 'Revisa la fecha y el horario.',
                    };
                }

                return;
            }

            if (!respuesta.ok) {
                estado.value = {
                    tipo: 'error',
                    mensaje:
                        respuesta.status === 429
                            ? 'Demasiadas consultas; espera un momento.'
                            : 'No se pudo consultar la disponibilidad.',
                };

                return;
            }

            const resultado =
                (await respuesta.json()) as VerificacionDisponibilidad;

            if (vigente()) {
                estado.value = { tipo: 'listo', resultado };
            }
        } catch {
            if (vigente()) {
                estado.value = {
                    tipo: 'error',
                    mensaje: 'No se pudo consultar la disponibilidad.',
                };
            }
        }
    };

    // Lanza la consulta (registrada en `enCurso` hasta que el servidor responde), salvo en pausa.
    const lanzar = (b: Bloque, id: number | null, numero: number) => {
        temporizador = undefined;

        if (pausadas) {
            pendiente = true;

            return;
        }

        const promesa = consultar(b, id, numero);
        enCurso.add(promesa);
        void promesa.finally(() => enCurso.delete(promesa));
    };

    const evaluar = () => {
        clearTimeout(temporizador);
        ultima++;

        const { id, ...b } = { ...bloque(), id: reservaId() };
        const completo = b.espacio_id && b.fecha && b.hora_inicio && b.hora_fin;

        if (!completo || b.hora_fin <= b.hora_inicio) {
            pendiente = false;
            estado.value = { tipo: 'incompleto' };

            return;
        }

        estado.value = { tipo: 'cargando' };

        if (pausadas) {
            pendiente = true;

            return;
        }

        const numero = ultima;
        temporizador = setTimeout(() => lanzar(b, id, numero), espera);
    };

    const control = {
        // Cancela la consulta programada; si había una, se repite al reanudar.
        pausar: () => {
            if (temporizador !== undefined) {
                clearTimeout(temporizador);
                temporizador = undefined;

                if (estado.value.tipo === 'cargando') {
                    pendiente = true;
                }
            }
        },
        reanudar: () => {
            if (pendiente && !desmontado) {
                pendiente = false;
                evaluar();
            }
        },
    };
    bloques.add(control);

    watch(() => ({ ...bloque(), id: reservaId() }), evaluar, {
        immediate: true,
    });

    onBeforeUnmount(() => {
        desmontado = true;
        clearTimeout(temporizador);
        bloques.delete(control);
    });

    return { estado };
}
