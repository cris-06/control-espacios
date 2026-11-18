<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use App\Models\EstadoReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\AnticipacionReserva;
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
    public function __construct(private readonly AnticipacionReserva $anticipacion) {}

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
            'calendario' => $this->calendario(
                $request->user(),
                isset($errores['fecha']) ? Date::today()->toDateString() : $filtros['fecha'],
                isset($errores['espacio_id']) ? null : $filtros['espacio_id'],
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function consultar(User $user, array $filtros): array
    {
        $bloques = $this->bloques($user, $filtros['fecha'], $filtros['fecha']);

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
     * Vista mensual: las reservas de cada día del mes de la fecha consultada (con el mismo
     * criterio de visibilidad que la agenda) y el primer día que se puede solicitar hoy;
     * los anteriores aparecen bloqueados.
     *
     * @return array{mes: string, fecha_minima: string, dias_anticipacion: int, dias: array<string, array<int, array<string, mixed>>>}
     */
    private function calendario(User $user, string $fecha, mixed $espacioId): array
    {
        $inicio = Date::parse($fecha)->startOfMonth();
        $colores = Espacio::query()->pluck('color_calendario', 'id');

        $dias = $this->bloques($user, $inicio->toDateString(), $inicio->copy()->endOfMonth()->toDateString())
            ->when($espacioId, fn (Collection $bloques, mixed $id) => $bloques->where('espacio_id', (int) $id))
            ->groupBy('fecha')
            ->map(fn (Collection $delDia): array => $delDia->map(fn (array $bloque): array => [
                'id' => $bloque['id'],
                'espacio' => $bloque['espacio'],
                'color' => $colores[$bloque['espacio_id']] ?? '#dc2626',
                'hora_inicio' => $bloque['hora_inicio'],
                'hora_fin' => $bloque['hora_fin'],
                'actividad' => $bloque['actividad'],
                'estado_id' => $bloque['estado_id'],
            ])->values()->all());

        return [
            'mes' => $inicio->format('Y-m'),
            'fecha_minima' => $this->anticipacion->fechaMinima()->toDateString(),
            'dias_anticipacion' => AnticipacionReserva::DIAS,
            'dias' => $dias->all(),
        ];
    }

    /**
     * Bloques que ocupan (o pueden llegar a ocupar) un espacio entre dos fechas. De las
     * reservas que el usuario no puede ver solo se sabe que el espacio está reservado.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function bloques(User $user, string $desde, string $hasta): Collection
    {
        $detalles = ReservaDetalle::query()
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->whereHas('reserva', fn ($q) => $q->whereIn('estado_id', self::ESTADOS_EN_CALENDARIO))
            // Una reserva autorizada tiene todos sus detalles autorizados; los denegados no ocupan.
            ->where(fn ($q) => $q->whereNull('estado_detalle')->orWhereIn('estado_detalle', [ReservaDetalle::PENDIENTE, ReservaDetalle::AUTORIZADA]))
            ->with(['espacio:id,nombre', 'reserva' => fn ($q) => $q->with(FilasReserva::RELACIONES_RESERVA)])
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
            ->get();

        $visibles = Reserva::query()
            ->visiblesPara($user)
            ->whereIn('id', $detalles->pluck('reserva_id')->unique())
            ->pluck('id')
            ->flip();

        return $detalles->map(fn (ReservaDetalle $detalle): array => FilasReserva::deDetalle($detalle, $visibles->has($detalle->reserva_id)));
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
