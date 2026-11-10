<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use App\Models\EstadoReserva;
use App\Models\Licenciatura;
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
 * Reportes por periodo (una fila por espacio y horario). El acceso lo restringe el
 * middleware 'rol:coordinador,admin,superadmin'; el coordinador solo ve sus
 * licenciaturas y pedir otra responde 403.
 */
class ReporteController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $hoy = Date::today();

        $filtros = [
            'fecha_inicio' => $request->query('fecha_inicio', $hoy->subDays(30)->toDateString()),
            'fecha_fin' => $request->query('fecha_fin', $hoy->addDays(30)->toDateString()),
            'espacio_id' => $request->query('espacio_id'),
            'licenciatura_id' => $request->query('licenciatura_id'),
            'estado_id' => $request->query('estado_id'),
        ];

        if (is_numeric($filtros['licenciatura_id'])) {
            abort_unless(
                $user->puedeConsultarLicenciatura((int) $filtros['licenciatura_id']),
                403,
                'No tienes acceso a los reportes de esa licenciatura.',
            );
        }

        $validador = Validator::make($filtros, [
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'espacio_id' => ['nullable', 'integer', Rule::exists('espacios', 'id')],
            'licenciatura_id' => ['nullable', 'integer', Rule::exists('licenciaturas', 'id')],
            'estado_id' => ['nullable', Rule::in(array_keys(EstadoReserva::ETIQUETAS))],
        ], [
            'fecha_inicio.required' => 'Indica la fecha de inicio del periodo.',
            'fecha_inicio.date_format' => 'La fecha de inicio no es válida.',
            'fecha_fin.required' => 'Indica la fecha de fin del periodo.',
            'fecha_fin.date_format' => 'La fecha de fin no es válida.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
            'espacio_id.exists' => 'El espacio seleccionado no existe.',
            'espacio_id.integer' => 'El espacio seleccionado no existe.',
            'licenciatura_id.exists' => 'La licenciatura seleccionada no existe.',
            'licenciatura_id.integer' => 'La licenciatura seleccionada no existe.',
            'estado_id.in' => 'El estado seleccionado no existe.',
        ]);

        $errores = $validador->errors()->toArray();
        $resultados = $errores === [] ? $this->consultar($user, $filtros) : collect();

        return Inertia::render('reportes/Index', [
            'filtros' => $filtros,
            'errores' => array_map(fn (array $mensajes): string => $mensajes[0], $errores),
            'opciones' => [
                'espacios' => Espacio::query()->orderBy('nombre')->get(['id', 'nombre']),
                'licenciaturas' => $user->licenciaturasConsultables()
                    ->map(fn (Licenciatura $l): array => ['id' => $l->id, 'nombre' => $l->nombre])
                    ->values(),
                'estados' => EstadoReserva::opciones(),
            ],
            'resultados' => $resultados->values(),
            'totalesEstado' => $resultados->countBy('estado_id')
                ->sortKeys()
                ->map(fn (int $total, int $id): array => ['nombre' => EstadoReserva::etiqueta($id), 'total' => $total])
                ->values(),
            'totalesEspacio' => $resultados->countBy('espacio')
                ->sortDesc()
                ->map(fn (int $total, string $nombre): array => ['nombre' => $nombre, 'total' => $total])
                ->values(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function consultar(User $user, array $filtros): Collection
    {
        return ReservaDetalle::query()
            ->whereHas('reserva', fn ($reserva) => $reserva
                ->visiblesPara($user)
                ->when($filtros['licenciatura_id'], fn ($q, mixed $id) => $q->where('licenciatura_id', (int) $id))
                ->when($filtros['estado_id'], fn ($q, mixed $id) => $q->where('estado_id', (int) $id)))
            ->when($filtros['espacio_id'], fn ($q, mixed $id) => $q->where('espacio_id', (int) $id))
            ->whereDate('fecha', '>=', $filtros['fecha_inicio'])
            ->whereDate('fecha', '<=', $filtros['fecha_fin'])
            ->with(['espacio:id,nombre', 'reserva' => fn ($q) => $q->with(FilasReserva::RELACIONES_RESERVA)])
            ->orderBy('fecha')
            ->orderBy('hora_inicio')
            ->limit(2000)
            ->get()
            ->map(fn (ReservaDetalle $detalle): array => FilasReserva::deDetalle($detalle));
    }
}
