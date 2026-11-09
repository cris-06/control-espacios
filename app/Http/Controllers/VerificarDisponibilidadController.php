<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use App\Models\Reserva;
use App\Models\User;
use App\Services\DisponibilidadEspacioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Consulta en vivo, desde el formulario de solicitud, de si un bloque (espacio,
 * fecha y horario) está libre. Solo informa: guardar el borrador nunca se bloquea.
 *
 * Privacidad: de cada choque solo se devuelven horario y estado; el folio solo si
 * el usuario puede ver esa reserva (DisponibilidadEspacioService::describir).
 */
class VerificarDisponibilidadController extends Controller
{
    public function __invoke(Request $request, DisponibilidadEspacioService $disponibilidad): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $datos = $request->validate([
            'espacio_id' => ['required', 'integer', Rule::exists('espacios', 'id')],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'reserva_id' => ['nullable', 'integer'],
        ], [
            'espacio_id.required' => 'Selecciona el espacio.',
            'espacio_id.exists' => 'El espacio no existe.',
            'fecha.required' => 'Indica la fecha.',
            'fecha.date_format' => 'La fecha no es válida.',
            'hora_inicio.required' => 'Indica la hora de inicio.',
            'hora_inicio.date_format' => 'La hora de inicio no es válida.',
            'hora_fin.required' => 'Indica la hora de fin.',
            'hora_fin.date_format' => 'La hora de fin no es válida.',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la de inicio.',
        ]);

        // Quien edita una reserva la excluye de sus propios choques; quien crea, debe poder crear.
        $reservaId = isset($datos['reserva_id']) ? (int) $datos['reserva_id'] : null;

        if ($reservaId !== null) {
            $reserva = Reserva::query()->find($reservaId);
            abort_unless($reserva !== null && $user->can('update', $reserva), 403);
        } else {
            abort_unless($user->can('create', Reserva::class), 403);
        }

        $espacio = Espacio::query()->findOrFail((int) $datos['espacio_id']);

        $choques = $disponibilidad->describir(
            $disponibilidad->choques(
                $espacio->id,
                $datos['fecha'],
                $datos['hora_inicio'],
                $datos['hora_fin'],
                excluirReservaId: $reservaId,
                incluirPendientes: true,
            ),
            $user,
        );

        $estados = array_column($choques, 'estado');

        return response()->json([
            'situacion' => match (true) {
                ! $espacio->estaDisponible() => 'fuera_de_servicio',
                in_array('autorizada', $estados, true) => 'ocupado',
                $choques !== [] => 'pendiente',
                default => 'libre',
            },
            'espacio' => [
                'estatus' => $espacio->estatus ?? 'disponible',
                'capacidad' => $espacio->capacidad,
                'minimo_alumnos' => $espacio->minimo_alumnos,
                'maximo_alumnos' => $espacio->maximo_alumnos,
            ],
            'choques' => $choques,
        ]);
    }
}
