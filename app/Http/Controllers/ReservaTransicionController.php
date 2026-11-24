<?php

namespace App\Http\Controllers;

use App\Exceptions\TransicionInvalida;
use App\Models\Reserva;
use App\Services\ReservaWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Ejecuta una acción del flujo (enviar, aprobar, solicitar corrección, autorizar,
 * denegar, cancelar, enviar a edición). Toda la lógica y los permisos están en
 * ReservaWorkflowService.
 *
 * Desde el listado de solicitudes (origen = listado) se regresa al listado para refrescarlo;
 * desde cualquier otro lugar, al detalle de la reserva.
 */
class ReservaTransicionController extends Controller
{
    public function __invoke(Request $request, Reserva $reserva, ReservaWorkflowService $workflow): RedirectResponse
    {
        $datos = $request->validate([
            'accion' => ['required', Rule::in(ReservaWorkflowService::acciones())],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'origen' => ['nullable', Rule::in(['listado'])],
        ]);

        try {
            $avisos = $workflow->ejecutar($reserva, $request->user(), $datos['accion'], $datos['observacion'] ?? null);
        } catch (TransicionInvalida $e) {
            return back()->withErrors(['transicion' => $e->getMessage()]);
        }

        $exito = match ($datos['accion']) {
            ReservaWorkflowService::CANCELAR => 'Listo: la reserva se canceló y el espacio quedó libre.',
            ReservaWorkflowService::ENVIAR_EDICION => 'Listo: la reserva regresó al solicitante para editarla.',
            default => 'Listo: la reserva cambió de estado.',
        };

        Inertia::flash('toast', $avisos === []
            ? ['type' => 'success', 'message' => $exito]
            : ['type' => 'warning', 'message' => 'Enviada, pero hay posibles choques de horario: '.implode(' ', $avisos)]);

        return ($datos['origen'] ?? null) === 'listado'
            ? back()
            : to_route('reservas.show', $reserva);
    }
}
