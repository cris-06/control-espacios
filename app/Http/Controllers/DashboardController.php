<?php

namespace App\Http\Controllers;

use App\Support\DatosEjemplo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-import-type FilaReserva from DatosEjemplo
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rol = DatosEjemplo::rolActual($request);
        $reservas = DatosEjemplo::reservasVisibles($rol);
        $hoy = Date::today()->toDateString();

        return Inertia::render('Dashboard', [
            'indicadores' => $this->indicadores($rol, $reservas, $hoy),
            'pendientes' => DatosEjemplo::porAtender($rol),
            'proximas' => $reservas
                ->where('estado_id', DatosEjemplo::AUTORIZADA)
                ->where('fecha', '>=', $hoy)
                ->sortBy(['fecha', 'hora_inicio'])
                ->take(5)
                ->values(),
        ]);
    }

    /**
     * @param  Collection<int, FilaReserva>  $reservas
     * @return list<array{titulo: string, valor: int, descripcion: string}>
     */
    private function indicadores(string $rol, Collection $reservas, string $hoy): array
    {
        $total = fn (int ...$estados): int => $reservas->whereIn('estado_id', $estados)->count();

        return match ($rol) {
            'docente' => [
                ['titulo' => 'Borradores', 'valor' => $total(DatosEjemplo::BORRADOR), 'descripcion' => 'Sin enviar a revisión'],
                ['titulo' => 'Por corregir', 'valor' => $total(DatosEjemplo::CORRECCION), 'descripcion' => 'Requieren tus ajustes'],
                ['titulo' => 'En revisión', 'valor' => $total(DatosEjemplo::PENDIENTE_COORDINADOR, DatosEjemplo::PENDIENTE_ADMIN), 'descripcion' => 'Esperando una decisión'],
                ['titulo' => 'Autorizadas', 'valor' => $total(DatosEjemplo::AUTORIZADA), 'descripcion' => 'Solicitudes aprobadas'],
            ],
            'coordinador' => [
                ['titulo' => 'Por revisar', 'valor' => $total(DatosEjemplo::PENDIENTE_COORDINADOR), 'descripcion' => 'De tus licenciaturas'],
                ['titulo' => 'En corrección', 'valor' => $total(DatosEjemplo::CORRECCION), 'descripcion' => 'Devueltas al docente'],
                ['titulo' => 'Con administración', 'valor' => $total(DatosEjemplo::PENDIENTE_ADMIN), 'descripcion' => 'Esperando autorización'],
                ['titulo' => 'Autorizadas', 'valor' => $total(DatosEjemplo::AUTORIZADA), 'descripcion' => 'De tus licenciaturas'],
            ],
            default => [
                ['titulo' => 'Por autorizar', 'valor' => $total(DatosEjemplo::PENDIENTE_ADMIN), 'descripcion' => 'Aprobadas por coordinación'],
                ['titulo' => 'Con coordinación', 'valor' => $total(DatosEjemplo::PENDIENTE_COORDINADOR), 'descripcion' => 'Aún en revisión'],
                ['titulo' => 'Reservaciones de hoy', 'valor' => $reservas->where('estado_id', DatosEjemplo::AUTORIZADA)->where('fecha', $hoy)->count(), 'descripcion' => 'Autorizadas para hoy'],
                ['titulo' => 'Espacios fuera de servicio', 'valor' => collect(DatosEjemplo::espacios())->where('estatus', '!=', 'disponible')->count(), 'descripcion' => 'Inactivos o en mantenimiento'],
            ],
        };
    }
}
