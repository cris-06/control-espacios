<?php

namespace App\Http\Controllers;

use App\Support\DatosEjemplo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-import-type FilaReserva from DatosEjemplo
 */
class DisponibilidadController extends Controller
{
    /** Solo estas reservas ocupan o pueden llegar a ocupar un espacio. */
    private const ESTADOS_EN_CALENDARIO = [
        DatosEjemplo::PENDIENTE_COORDINADOR,
        DatosEjemplo::PENDIENTE_ADMIN,
        DatosEjemplo::AUTORIZADA,
    ];

    public function index(Request $request): Response
    {
        $filtros = [
            'fecha' => $request->query('fecha', Date::today()->toDateString()),
            'espacio_id' => $request->query('espacio_id'),
            'hora_inicio' => $request->query('hora_inicio'),
            'hora_fin' => $request->query('hora_fin'),
        ];

        $espacios = collect(DatosEjemplo::espacios());

        $validador = Validator::make($filtros, [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'espacio_id' => ['nullable', Rule::in($espacios->keys()->all())],
            'hora_inicio' => ['nullable', 'date_format:H:i', 'required_with:hora_fin'],
            'hora_fin' => ['nullable', 'date_format:H:i', 'required_with:hora_inicio', 'after:hora_inicio'],
        ], [
            'fecha.required' => 'Selecciona una fecha.',
            'fecha.date_format' => 'La fecha no es válida.',
            'espacio_id.in' => 'El espacio seleccionado no existe.',
            'hora_inicio.date_format' => 'La hora de inicio no es válida.',
            'hora_inicio.required_with' => 'Indica también la hora de inicio.',
            'hora_fin.date_format' => 'La hora de fin no es válida.',
            'hora_fin.required_with' => 'Indica también la hora de fin.',
            'hora_fin.after' => 'La hora de fin debe ser posterior a la de inicio.',
        ]);

        $errores = $validador->errors()->toArray();

        return Inertia::render('disponibilidad/Index', [
            'filtros' => $filtros,
            'errores' => array_map(fn (array $mensajes): string => $mensajes[0], $errores),
            'opcionesEspacios' => $espacios->map(fn (array $espacio): array => ['id' => $espacio['id'], 'nombre' => $espacio['nombre']])->values(),
            'espacios' => $errores === [] ? $this->consultar($filtros) : [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function consultar(array $filtros): array
    {
        $reservas = DatosEjemplo::reservas()
            ->where('fecha', $filtros['fecha'])
            ->whereIn('estado_id', self::ESTADOS_EN_CALENDARIO)
            ->sortBy('hora_inicio');

        return collect(DatosEjemplo::espacios())
            ->when($filtros['espacio_id'], fn (Collection $espacios, mixed $id) => $espacios->where('id', (int) $id))
            ->map(function (array $espacio) use ($reservas, $filtros): array {
                $bloques = $reservas->where('espacio_id', $espacio['id'])->values();

                return [
                    ...$espacio,
                    'situacion' => $this->situacion($espacio['estatus'], $bloques, $filtros['hora_inicio'], $filtros['hora_fin']),
                    'bloques' => $bloques,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Un espacio sin servicio nunca se ofrece como disponible. Con un horario consultado,
     * una reserva autorizada lo ocupa y una pendiente solo advierte de un posible conflicto.
     *
     * @param  Collection<int, FilaReserva>  $bloques
     */
    private function situacion(string $estatus, Collection $bloques, mixed $inicio, mixed $fin): ?string
    {
        if ($estatus !== 'disponible') {
            return 'fuera_de_servicio';
        }

        if ($inicio === null || $fin === null) {
            return null;
        }

        $traslapes = $bloques->filter(fn (array $bloque): bool => $bloque['hora_inicio'] < $fin && $inicio < $bloque['hora_fin']);

        return match (true) {
            $traslapes->contains('estado_id', DatosEjemplo::AUTORIZADA) => 'ocupado',
            $traslapes->isNotEmpty() => 'pendiente',
            default => 'libre',
        };
    }
}
