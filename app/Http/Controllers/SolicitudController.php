<?php

namespace App\Http\Controllers;

use App\Models\EstadoReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use App\Support\FilasReserva;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Listado de reservas. Cada rol solo ve lo que le corresponde
 * (Reserva::scopeVisiblesPara, mismo criterio que ReservaPolicy::view).
 * Filtrar por una licenciatura ajena responde 403.
 */
class SolicitudController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $estadoId = $request->integer('estado_id') ?: null;
        $licenciaturaId = $request->integer('licenciatura_id') ?: null;

        abort_if(
            $licenciaturaId !== null && ! $user->puedeConsultarLicenciatura($licenciaturaId),
            403,
            'No tienes acceso a las reservas de esa licenciatura.',
        );

        $reservas = Reserva::query()
            ->visiblesPara($user)
            ->when($estadoId, fn ($query, int $id) => $query->where('estado_id', $id))
            ->when($licenciaturaId, fn ($query, int $id) => $query->where('licenciatura_id', $id))
            ->with([...FilasReserva::RELACIONES_RESERVA, 'detalles.espacio:id,nombre'])
            ->orderByDesc('fecha_actualizacion')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('solicitudes/Index', [
            'filtros' => ['estado_id' => $estadoId, 'licenciatura_id' => $licenciaturaId],
            'opcionesEstados' => EstadoReserva::opciones(),
            'opcionesLicenciaturas' => $user->licenciaturasConsultables()
                ->map(fn (Licenciatura $l): array => ['id' => $l->id, 'nombre' => $l->nombre])
                ->values(),
            'solicitudes' => $reservas->map(fn (Reserva $reserva): array => FilasReserva::deReserva($reserva)),
            'puedeCrear' => $user->can('create', Reserva::class),
        ]);
    }
}
