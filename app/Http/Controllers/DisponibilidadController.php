<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use App\Models\EstadoReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Support\FilasReserva;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ocupación de espacios por día. Cualquier rol ve qué espacio está ocupado, pero
 * de las reservas que no puede ver (Reserva::visiblesPara) solo aparece "Reservado".
 */
class DisponibilidadController extends Controller
{
    /** Solo estas reservas ocupan o pueden llegar a ocupar un espacio. */
    private const ESTADOS_EN_CALENDARIO = [
        EstadoReserva::PENDIENTE_COORDINADOR,
        EstadoReserva::PENDIENTE_ADMIN,
        EstadoReserva::AUTORIZADA,
    ];

    public function index(Request $request): Response
    {
        $filtros = [
            'fecha' => $request->query('fecha', Date::today()->toDateString()),
            'espacio_id' => $request->query('espacio_id'),
            'hora_inicio' => $request->query('hora_inicio'),
            'hora_fin' => $request->query('hora_fin'),
        ];

        $validador = Validator::make($filtros, [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'espacio_id' => ['nullable', 'integer', Rule::exists('espacios', 'id')],
            'hora_inicio' => ['nullable', 'date_format:H:i', 'required_with:hora_fin'],
            'hora_fin' => ['nullable', 'date_format:H:i', 'required_with:hora_inicio', 'after:hora_inicio'],
        ], [
            'fecha.required' => 'Selecciona una fecha.',
            'fecha.date_format' => 'La fecha no es válida.',
            'espacio_id.exists' => 'El espacio seleccionado no existe.',
            'espacio_id.integer' => 'El espacio seleccionado no existe.',
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
            'opcionesEspacios' => Espacio::query()->orderBy('nombre')->get(['id', 'nombre']),
            'espacios' => $errores === [] ? $this->consultar($request->user(), $filtros) : [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function consultar(User $user, array $filtros): array
    {
        $detalles = ReservaDetalle::query()
            ->whereDate('fecha', $filtros['fecha'])
            ->whereHas('reserva', fn ($q) => $q->whereIn('estado_id', self::ESTADOS_EN_CALENDARIO))
            // Una reserva autorizada tiene todos sus detalles autorizados; los denegados no ocupan.
            ->where(fn ($q) => $q->whereNull('estado_detalle')->orWhereIn('estado_detalle', [ReservaDetalle::PENDIENTE, ReservaDetalle::AUTORIZADA]))
            ->with(['espacio:id,nombre', 'reserva' => fn ($q) => $q->with(FilasReserva::RELACIONES_RESERVA)])
            ->orderBy('hora_inicio')
            ->get();

        $visibles = Reserva::query()
            ->visiblesPara($user)
            ->whereIn('id', $detalles->pluck('reserva_id')->unique())
            ->pluck('id')
            ->flip();

        $bloques = $detalles->map(fn (ReservaDetalle $detalle): array => FilasReserva::deDetalle($detalle, $visibles->has($detalle->reserva_id)));

        // Los espacios inactivos o en mantenimiento también se listan, como fuera de servicio.
        return Espacio::query()
            ->when($filtros['espacio_id'], fn ($q, mixed $id) => $q->whereKey((int) $id))
            ->orderBy('nombre')
            ->get()
            ->map(function (Espacio $espacio) use ($bloques, $filtros): array {
                $propios = $bloques->where('espacio_id', $espacio->id)->values();

                return [
                    'id' => $espacio->id,
                    'nombre' => $espacio->nombre,
                    'ubicacion' => $espacio->ubicacion ?? '',
                    'capacidad' => $espacio->capacidad ?? 0,
                    'color_calendario' => $espacio->color_calendario ?? '#dc2626',
                    'estatus' => $espacio->estatus ?? 'disponible',
                    'situacion' => $this->situacion($espacio->estatus ?? 'disponible', $propios, $filtros['hora_inicio'], $filtros['hora_fin']),
                    'bloques' => $propios,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Un espacio sin servicio nunca se ofrece como disponible. Con un horario consultado,
     * una reserva autorizada lo ocupa y una pendiente solo advierte de un posible conflicto.
     *
     * @param  Collection<int, array<string, mixed>>  $bloques
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
            $traslapes->contains('estado_id', EstadoReserva::AUTORIZADA) => 'ocupado',
            $traslapes->isNotEmpty() => 'pendiente',
            default => 'libre',
        };
    }
}
