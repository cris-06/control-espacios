<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReprogramarReservaRequest;
use App\Models\Reserva;
use App\Services\ReservaWorkflowService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Reprograma (fecha, horario y, opcionalmente, espacio) un bloque de una reserva autorizada.
 * Permisos en ReprogramarReservaRequest / ReservaPolicy::reprogramar; la validación de
 * choques y el registro en historial y notificaciones, en ReservaWorkflowService::reprogramar.
 */
class ReservaReprogramacionController extends Controller
{
    public function __invoke(ReprogramarReservaRequest $request, Reserva $reserva, ReservaWorkflowService $workflow): RedirectResponse
    {
        /** @var array{detalle_id: int|string, espacio_id: int|string, fecha: string, hora_inicio: string, hora_fin: string, motivo: string} $datos */
        $datos = $request->validated();

        $workflow->reprogramar($reserva, $request->user(), [
            'detalle_id' => (int) $datos['detalle_id'],
            'espacio_id' => (int) $datos['espacio_id'],
            'fecha' => $datos['fecha'],
            'hora_inicio' => $datos['hora_inicio'],
            'hora_fin' => $datos['hora_fin'],
            'motivo' => $datos['motivo'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Listo: la reserva se reprogramó.']);

        return back();
    }
}
