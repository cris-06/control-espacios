<?php

namespace App\Http\Controllers;

use App\Models\EstadoReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Listado de reservas reales. Cada rol solo ve lo que le corresponde
 * (Reserva::scopeVisiblesPara, mismo criterio que ReservaPolicy::view).
 */
class SolicitudController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $estadoId = $request->integer('estado_id') ?: null;

        $reservas = Reserva::query()
            ->visiblesPara($user)
            ->when($estadoId, fn ($query, int $id) => $query->where('estado_id', $estadoId))
            ->with([
                'creador:id,nombre,apellido_paterno,apellido_materno',
                'licenciatura:id,nombre',
                'actividad:id,nombre',
                'detalles.espacio:id,nombre',
            ])
            ->orderByDesc('fecha_actualizacion')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('solicitudes/Index', [
            'filtros' => ['estado_id' => $estadoId],
            'opcionesEstados' => EstadoReserva::opciones(),
            'solicitudes' => $reservas->map(fn (Reserva $reserva): array => $this->fila($reserva)),
            'puedeCrear' => $user->can('create', Reserva::class),
        ]);
    }

    /**
     * Una fila por reserva; fecha, horario y espacio son los del primer detalle.
     *
     * @return array<string, mixed>
     */
    private function fila(Reserva $reserva): array
    {
        /** @var ReservaDetalle|null $primero */
        $primero = $reserva->detalles->first();
        $extra = $reserva->detalles->count() - 1;

        return [
            'id' => $reserva->id,
            'folio' => $reserva->folio ?? Reserva::folioPara($reserva->id),
            'actividad' => $reserva->nombre_actividad ?: $reserva->actividad->nombre,
            'usuario_creador_id' => $reserva->usuario_creador_id,
            'docente' => $reserva->creador->name,
            'licenciatura_id' => $reserva->licenciatura_id,
            'licenciatura' => $reserva->licenciatura->nombre,
            'espacio_id' => $primero?->espacio_id,
            'espacio' => $primero ? $primero->espacio->nombre.($extra > 0 ? " (+{$extra})" : '') : null,
            'fecha' => $primero?->fecha->toDateString(),
            'hora_inicio' => $primero ? ReservaDetalle::hhmm($primero->hora_inicio) : null,
            'hora_fin' => $primero ? ReservaDetalle::hhmm($primero->hora_fin) : null,
            'cantidad_alumnos' => $primero?->cantidad_alumnos,
            'estado_id' => $reserva->estado_id,
            'estado' => EstadoReserva::etiqueta($reserva->estado_id),
        ];
    }
}
