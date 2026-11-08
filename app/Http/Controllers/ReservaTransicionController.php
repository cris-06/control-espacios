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
 * denegar). Toda la lógica y los permisos están en ReservaWorkflowService.
 */
class ReservaTransicionController extends Controller
{
    public function __invoke(Request $request, Reserva $reserva, ReservaWorkflowService $workflow): RedirectResponse
    {
        $datos = $request->validate([
            'accion' => ['required', Rule::in([
                ReservaWorkflowService::ENVIAR,
                ReservaWorkflowService::APROBAR,
                ReservaWorkflowService::SOLICITAR_CORRECCION,
                ReservaWorkflowService::AUTORIZAR,
                ReservaWorkflowService::DENEGAR,
            ])],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $avisos = $workflow->ejecutar($reserva, $request->user(), $datos['accion'], $datos['observacion'] ?? null);
        } catch (TransicionInvalida $e) {
            return back()->withErrors(['transicion' => $e->getMessage()]);
        }

        Inertia::flash('toast', $avisos === []
            ? ['type' => 'success', 'message' => 'Listo: la reserva cambió de estado.']
            : ['type' => 'warning', 'message' => 'Enviada, pero hay posibles choques de horario: '.implode(' ', $avisos)]);

        return to_route('reservas.show', $reserva);
    }
}
