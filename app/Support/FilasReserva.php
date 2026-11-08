<?php

namespace App\Support;

use App\Models\EstadoReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;

/**
 * Formato común de las filas que muestran los listados (Solicitudes, Panel, Reportes
 * y Disponibilidad). Coincide con los tipos Reserva / ReservaFila del frontend.
 */
class FilasReserva
{
    /** Relaciones que necesitan las filas. */
    public const RELACIONES_RESERVA = [
        'creador:id,nombre,apellido_paterno,apellido_materno',
        'licenciatura:id,nombre',
        'actividad:id,nombre',
    ];

    /**
     * Una fila por reserva; fecha, horario y espacio son los del primer detalle.
     * Requiere RELACIONES_RESERVA y detalles.espacio cargados.
     *
     * @return array<string, mixed>
     */
    public static function deReserva(Reserva $reserva): array
    {
        /** @var ReservaDetalle|null $primero */
        $primero = $reserva->detalles->first();
        $extra = $reserva->detalles->count() - 1;

        return [
            ...self::datosReserva($reserva),
            'id' => $reserva->id,
            'espacio_id' => $primero?->espacio_id,
            'espacio' => $primero ? $primero->espacio->nombre.($extra > 0 ? " (+{$extra})" : '') : null,
            'fecha' => $primero?->fecha->toDateString(),
            'hora_inicio' => $primero ? ReservaDetalle::hhmm($primero->hora_inicio) : null,
            'hora_fin' => $primero ? ReservaDetalle::hhmm($primero->hora_fin) : null,
            'cantidad_alumnos' => $primero?->cantidad_alumnos,
        ];
    }

    /**
     * Una fila por detalle (bloque de espacio y horario). Si el usuario no puede ver la
     * reserva, se ocultan actividad, solicitante y folio: solo se sabe que está ocupado.
     * Requiere reserva (con RELACIONES_RESERVA) y espacio cargados.
     *
     * @return array<string, mixed>
     */
    public static function deDetalle(ReservaDetalle $detalle, bool $visible = true): array
    {
        $reserva = $detalle->reserva;

        $datos = $visible ? self::datosReserva($reserva) : [
            'reserva_id' => null,
            'folio' => '—',
            'actividad' => 'Reservado',
            'usuario_creador_id' => null,
            'docente' => '—',
            'licenciatura_id' => null,
            'licenciatura' => '—',
            'estado_id' => $reserva->estado_id,
            'estado' => EstadoReserva::etiqueta($reserva->estado_id),
        ];

        return [
            ...$datos,
            'id' => $detalle->id,
            'visible' => $visible,
            'espacio_id' => $detalle->espacio_id,
            'espacio' => $detalle->espacio->nombre,
            'fecha' => $detalle->fecha->toDateString(),
            'hora_inicio' => ReservaDetalle::hhmm($detalle->hora_inicio),
            'hora_fin' => ReservaDetalle::hhmm($detalle->hora_fin),
            'cantidad_alumnos' => $detalle->cantidad_alumnos,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function datosReserva(Reserva $reserva): array
    {
        return [
            'reserva_id' => $reserva->id,
            'folio' => $reserva->folio ?? Reserva::folioPara($reserva->id),
            'actividad' => $reserva->nombre_actividad ?: $reserva->actividad->nombre,
            'usuario_creador_id' => $reserva->usuario_creador_id,
            'docente' => $reserva->creador->name,
            'licenciatura_id' => $reserva->licenciatura_id,
            'licenciatura' => $reserva->licenciatura->nombre,
            'estado_id' => $reserva->estado_id,
            'estado' => EstadoReserva::etiqueta($reserva->estado_id),
        ];
    }
}
