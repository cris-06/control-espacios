<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Support\FilasReserva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel por rol con datos reales, limitado a lo que el usuario puede ver
 * (Reserva::visiblesPara).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $hoy = Date::today()->toDateString();

        return Inertia::render('Dashboard', [
            'indicadores' => $this->indicadores($user, $hoy),
            'pendientes' => Reserva::query()
                ->porAtender($user)
                ->with([...FilasReserva::RELACIONES_RESERVA, 'detalles.espacio:id,nombre'])
                ->orderBy('fecha_actualizacion')
                ->limit(10)
                ->get()
                ->map(fn (Reserva $reserva): array => FilasReserva::deReserva($reserva)),
            'proximas' => ReservaDetalle::query()
                ->whereHas('reserva', fn ($q) => $q->visiblesPara($user)->where('estado_id', E::AUTORIZADA))
                ->whereDate('fecha', '>=', $hoy)
                ->with(['espacio:id,nombre', 'reserva' => fn ($q) => $q->with(FilasReserva::RELACIONES_RESERVA)])
                ->orderBy('fecha')
                ->orderBy('hora_inicio')
                ->limit(5)
                ->get()
                ->map(fn (ReservaDetalle $detalle): array => FilasReserva::deDetalle($detalle)),
        ]);
    }

    /**
     * @return list<array{titulo: string, valor: int, descripcion: string}>
     */
    private function indicadores(User $user, string $hoy): array
    {
        $total = fn (int ...$estados): int => Reserva::query()->visiblesPara($user)->whereIn('estado_id', $estados)->count();
        $propias = fn (int ...$estados): int => Reserva::query()->where('usuario_creador_id', $user->id)->whereIn('estado_id', $estados)->count();

        if ($user->esAdministrador()) {
            return [
                ['titulo' => 'Por autorizar', 'valor' => $total(E::PENDIENTE_ADMIN), 'descripcion' => 'Aprobadas por coordinación'],
                ['titulo' => 'Con coordinación', 'valor' => $total(E::PENDIENTE_COORDINADOR), 'descripcion' => 'Aún en revisión'],
                ['titulo' => 'Reservaciones de hoy', 'valor' => ReservaDetalle::query()
                    ->whereDate('fecha', $hoy)
                    ->whereHas('reserva', fn ($q) => $q->where('estado_id', E::AUTORIZADA))
                    ->count(), 'descripcion' => 'Autorizadas para hoy'],
                ['titulo' => 'Espacios fuera de servicio', 'valor' => Espacio::query()->where('estatus', '!=', 'disponible')->count(), 'descripcion' => 'Inactivos o en mantenimiento'],
            ];
        }

        if ($user->esCoordinador()) {
            return [
                ['titulo' => 'Por revisar', 'valor' => Reserva::query()->porAtender($user)->where('estado_id', E::PENDIENTE_COORDINADOR)->count(), 'descripcion' => 'De tus licenciaturas'],
                ['titulo' => 'En corrección', 'valor' => $total(E::CORRECCION), 'descripcion' => 'Devueltas para ajustes'],
                ['titulo' => 'Con administración', 'valor' => $total(E::PENDIENTE_ADMIN), 'descripcion' => 'Esperando autorización'],
                ['titulo' => 'Autorizadas', 'valor' => $total(E::AUTORIZADA), 'descripcion' => 'De tus licenciaturas'],
            ];
        }

        return [
            ['titulo' => 'Borradores', 'valor' => $propias(E::BORRADOR), 'descripcion' => 'Sin enviar a revisión'],
            ['titulo' => 'Por corregir', 'valor' => $propias(E::CORRECCION), 'descripcion' => 'Requieren tus ajustes'],
            ['titulo' => 'En revisión', 'valor' => $propias(E::PENDIENTE_COORDINADOR, E::PENDIENTE_ADMIN), 'descripcion' => 'Esperando una decisión'],
            ['titulo' => 'Autorizadas', 'valor' => $propias(E::AUTORIZADA), 'descripcion' => 'Solicitudes aprobadas'],
        ];
    }
}
