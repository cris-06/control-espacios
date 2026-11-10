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
    let controlador: AbortController | undefined;

    const cancelar = () => {
        clearTimeout(temporizador);
        controlador?.abort();
    };

    const consultar = async (b: Bloque, id: number | null) => {
        controlador = new AbortController();

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
                    signal: controlador.signal,
                },
            );

            if (respuesta.status === 422) {
                const cuerpo = (await respuesta.json()) as {
                    message?: string;
                };
                estado.value = {
                    tipo: 'error',
                    mensaje: cuerpo.message ?? 'Revisa la fecha y el horario.',
                };

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

            estado.value = {
                tipo: 'listo',
                resultado:
                    (await respuesta.json()) as VerificacionDisponibilidad,
            };
        } catch (e) {
            if ((e as Error).name !== 'AbortError') {
                estado.value = {
                    tipo: 'error',
                    mensaje: 'No se pudo consultar la disponibilidad.',
                };
            }
        }
    };

    watch(
        () => ({ ...bloque(), id: reservaId() }),
        ({ id, ...b }) => {
            cancelar();

            const completo =
                b.espacio_id && b.fecha && b.hora_inicio && b.hora_fin;

            if (!completo || b.hora_fin <= b.hora_inicio) {
                estado.value = { tipo: 'incompleto' };

                return;
            }

            estado.value = { tipo: 'cargando' };
            temporizador = setTimeout(() => void consultar(b, id), espera);
        },
        { immediate: true },
    );

    onBeforeUnmount(cancelar);

    return { estado };
}
