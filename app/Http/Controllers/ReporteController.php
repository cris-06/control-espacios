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
class ReporteController extends Controller
{
    public function index(Request $request): Response
    {
        $rol = DatosEjemplo::rolActual($request);

        abort_if($rol === 'docente', 403);

        $hoy = Date::today();
        $licenciaturas = DatosEjemplo::licenciaturasVisibles($rol);

        $filtros = [
            'fecha_inicio' => $request->query('fecha_inicio', $hoy->subDays(30)->toDateString()),
            'fecha_fin' => $request->query('fecha_fin', $hoy->addDays(30)->toDateString()),
            'espacio_id' => $request->query('espacio_id'),
            'licenciatura_id' => $request->query('licenciatura_id'),
            'estado_id' => $request->query('estado_id'),
        ];

        $validador = Validator::make($filtros, [
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'espacio_id' => ['nullable', Rule::in(array_keys(DatosEjemplo::espacios()))],
            'licenciatura_id' => ['nullable', Rule::in(array_keys($licenciaturas))],
            'estado_id' => ['nullable', Rule::in(array_keys(DatosEjemplo::ESTADOS))],
        ], [
            'fecha_inicio.required' => 'Indica la fecha de inicio del periodo.',
            'fecha_inicio.date_format' => 'La fecha de inicio no es válida.',
            'fecha_fin.required' => 'Indica la fecha de fin del periodo.',
            'fecha_fin.date_format' => 'La fecha de fin no es válida.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
            'espacio_id.in' => 'El espacio seleccionado no existe.',
            'licenciatura_id.in' => 'La licenciatura seleccionada no está disponible.',
            'estado_id.in' => 'El estado seleccionado no existe.',
        ]);

        $errores = $validador->errors()->toArray();
        $resultados = $errores === [] ? $this->consultar($rol, $filtros) : collect();

        return Inertia::render('reportes/Index', [
            'filtros' => $filtros,
            'errores' => array_map(fn (array $mensajes): string => $mensajes[0], $errores),
            'opciones' => [
                'espacios' => collect(DatosEjemplo::espacios())->map(fn (array $espacio): array => ['id' => $espacio['id'], 'nombre' => $espacio['nombre']])->values(),
                'licenciaturas' => $this->opciones($licenciaturas),
                'estados' => $this->opciones(DatosEjemplo::ESTADOS),
            ],
            'resultados' => $resultados,
            'totalesEstado' => $resultados->countBy('estado_id')
                ->sortKeys()
                ->map(fn (int $total, int $id): array => ['nombre' => DatosEjemplo::ESTADOS[$id], 'total' => $total])
                ->values(),
            'totalesEspacio' => $resultados->countBy('espacio')
                ->sortDesc()
                ->map(fn (int $total, string $nombre): array => ['nombre' => $nombre, 'total' => $total])
                ->values(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, FilaReserva>
     */
    private function consultar(string $rol, array $filtros): Collection
    {
        return DatosEjemplo::reservasVisibles($rol)
            ->where('fecha', '>=', $filtros['fecha_inicio'])
            ->where('fecha', '<=', $filtros['fecha_fin'])
            ->when($filtros['espacio_id'], fn (Collection $filas, mixed $id) => $filas->where('espacio_id', (int) $id))
            ->when($filtros['licenciatura_id'], fn (Collection $filas, mixed $id) => $filas->where('licenciatura_id', (int) $id))
            ->when($filtros['estado_id'], fn (Collection $filas, mixed $id) => $filas->where('estado_id', (int) $id))
            ->sortBy(['fecha', 'hora_inicio'])
            ->values();
    }

    /**
     * @param  array<int, string>  $catalogo
     * @return list<array{id: int, nombre: string}>
     */
    private function opciones(array $catalogo): array
    {
        return array_map(
            fn (int $id, string $nombre): array => ['id' => $id, 'nombre' => $nombre],
            array_keys($catalogo),
            $catalogo,
        );
    }
}
