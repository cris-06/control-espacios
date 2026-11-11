<?php

namespace App\Services;

use App\Models\EstadoReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * Anticipación mínima del Formato 10: cada espacio se solicita con al menos DIAS días
 * naturales de anticipación, contados desde la fecha del servidor (zona del proyecto).
 * Exactamente DIAS días es válido.
 *
 * - Primer envío (la reserva está en borrador): todos los bloques deben cumplirla.
 * - Después del primer envío: al GUARDAR, todo bloque nuevo o con espacio, fecha u horario
 *   distintos de lo guardado debe cumplirla respecto al día del cambio; los bloques sin esos
 *   cambios conservan su fecha. Al REENVIAR solo se rechazan fechas que ya pasaron.
 *
 * Pendiente: confirmar con el CUV si son días naturales o hábiles (hoy, naturales).
 */
class AnticipacionReserva
{
    public const DIAS = 10;

    public function fechaMinima(): CarbonInterface
    {
        return Date::today()->addDays(self::DIAS);
    }

    /**
     * Ninguna transición regresa a borrador: cualquier otro estado ya pasó por un envío.
     * La fecha de entrega cubre además cualquier caso en que se haya registrado el envío.
     */
    public function yaEnviada(Reserva $reserva): bool
    {
        return $reserva->estado_id !== EstadoReserva::BORRADOR || $reserva->fecha_entrega_formato !== null;
    }

    /**
     * Validación del envío (dentro de la transacción de ReservaWorkflowService::ejecutar).
     *
     * @throws ValidationException con claves reserva.N
     */
    public function validarEnvio(Reserva $reserva): void
    {
        /** @var Collection<int, ReservaDetalle> $detalles */
        $detalles = $reserva->detalles()->with('espacio:id,nombre')->get();
        $primerEnvio = ! $this->yaEnviada($reserva);
        $minima = $this->fechaMinima()->toDateString();
        $hoy = Date::today()->toDateString();
        $mensajes = [];

        foreach ($detalles->values() as $i => $detalle) {
            $fecha = $detalle->fecha->toDateString();
            $bloque = 'Espacio '.($i + 1).' ('.($detalle->espacio->nombre ?? 'sin espacio').', '.$detalle->fecha->format('d/m/Y').')';

            if ($primerEnvio && $fecha < $minima) {
                $mensajes[] = "$bloque: {$this->requisito()}";
            } elseif (! $primerEnvio && $fecha < $hoy) {
                $mensajes[] = "$bloque: la fecha ya pasó; cámbiala por una a partir del {$this->fechaMinima()->format('d/m/Y')}.";
            }
        }

        if ($mensajes !== []) {
            $porClave = [];

            foreach ($mensajes as $i => $mensaje) {
                $porClave["reserva.$i"] = $mensaje;
            }

            throw ValidationException::withMessages($porClave);
        }
    }

    /**
     * Validación al guardar una reserva ya enviada (dentro de la transacción de
     * ReservaService::actualizar y antes de escribir nada). Compara lo recibido con lo
     * guardado en la base: un bloque sin id (nuevo, o borrado y vuelto a crear) o con otro
     * espacio, fecha, hora de inicio u hora de fin debe cumplir la anticipación.
     *
     * @param  list<array<string, mixed>>  $detalles  datos validados por ReservaRequest
     *
     * @throws ValidationException con claves detalles.N.fecha
     */
    public function validarCambios(Reserva $reserva, array $detalles): void
    {
        if (! $this->yaEnviada($reserva)) {
            return;
        }

        /** @var Collection<int, ReservaDetalle> $guardados */
        $guardados = ReservaDetalle::query()->where('reserva_id', $reserva->id)->get()->keyBy('id');
        $minima = $this->fechaMinima()->toDateString();
        $errores = [];

        foreach ($detalles as $i => $datos) {
            $id = $datos['id'] ?? null;
            $guardado = $id !== null ? $guardados->get($id) : null;

            if ($guardado !== null && ! $this->cambio($guardado, $datos)) {
                continue;
            }

            if ((string) $datos['fecha'] < $minima) {
                $errores["detalles.$i.fecha"] = ($guardado === null ? 'Espacio nuevo: ' : 'Cambiaste el espacio, la fecha o el horario: ').$this->requisito();
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function cambio(ReservaDetalle $guardado, array $datos): bool
    {
        return (int) $datos['espacio_id'] !== $guardado->espacio_id
            || (string) $datos['fecha'] !== $guardado->fecha->toDateString()
            || ReservaDetalle::hhmm((string) $datos['hora_inicio']) !== ReservaDetalle::hhmm($guardado->hora_inicio)
            || ReservaDetalle::hhmm((string) $datos['hora_fin']) !== ReservaDetalle::hhmm($guardado->hora_fin);
    }

    private function requisito(): string
    {
        return 'se requieren '.self::DIAS.' días de anticipación; elige una fecha a partir del '.$this->fechaMinima()->format('d/m/Y').'.';
    }
}
