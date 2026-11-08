<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservaRequest;
use App\Models\Actividad;
use App\Models\Asignatura;
use App\Models\Cuatrimestre;
use App\Models\Espacio;
use App\Models\EstadoReserva;
use App\Models\HistorialReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\ReservaService;
use App\Services\ReservaWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Crear, ver y editar reservas. Los cambios de estado van por ReservaTransicionController.
 */
class ReservaController extends Controller
{
    public function __construct(
        private readonly ReservaService $reservas,
        private readonly ReservaWorkflowService $workflow,
    ) {}

    public function create(Request $request): Response
    {
        Gate::authorize('create', Reserva::class);

        return Inertia::render('reservas/Form', [
            'reserva' => null,
            'opciones' => $this->opciones($request->user()),
        ]);
    }

    public function store(ReservaRequest $request): RedirectResponse
    {
        Gate::authorize('create', Reserva::class);

        $reserva = $this->reservas->crear($request->user(), $request->datosReserva(), $request->datosDetalles());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Reserva {$reserva->folio} guardada como borrador."]);

        return to_route('reservas.show', $reserva);
    }

    public function show(Request $request, Reserva $reserva): Response
    {
        Gate::authorize('view', $reserva);

        /** @var User $user */
        $user = $request->user();

        $reserva->load([
            'creador:id,nombre,apellido_paterno,apellido_materno,correo',
            'coordinador:id,nombre,apellido_paterno,apellido_materno',
            'licenciatura:id,nombre',
            'actividad:id,nombre',
            'cuatrimestre:id,nombre',
            'asignatura:id,nombre',
            'estado:id,nombre,descripcion',
            'detalles.espacio:id,nombre,ubicacion,capacidad',
            'historial.usuario:id,nombre,apellido_paterno,apellido_materno',
        ]);

        return Inertia::render('reservas/Show', [
            'reserva' => [
                ...$this->datosFormulario($reserva),
                'folio' => $reserva->folio,
                'estado' => ['id' => $reserva->estado_id, 'nombre' => EstadoReserva::etiqueta($reserva->estado_id), 'descripcion' => $reserva->estado->descripcion],
                'creador' => $reserva->creador->name,
                'coordinador' => $reserva->coordinador?->name,
                'licenciatura' => $reserva->licenciatura->nombre,
                'actividad' => $reserva->actividad->nombre,
                'cuatrimestre' => $reserva->cuatrimestre?->nombre,
                'asignatura' => $reserva->asignatura?->nombre,
                'observaciones_admin' => $reserva->observaciones_admin,
                'detalles' => $reserva->detalles->map(fn (ReservaDetalle $detalle): array => [
                    ...$this->datosDetalle($detalle),
                    'espacio' => $detalle->espacio->only(['nombre', 'ubicacion', 'capacidad']),
                    'estado_detalle' => $detalle->estado_detalle,
                ]),
                'historial' => $reserva->historial->map(fn (HistorialReserva $registro): array => [
                    'id' => $registro->id,
                    'accion' => $registro->accion,
                    'comentario' => $registro->comentario,
                    'usuario' => $registro->usuario->name,
                    'fecha' => $registro->fecha?->toIso8601String(),
                ]),
            ],
            'puede' => [
                'editar' => $user->can('update', $reserva),
            ],
            'acciones' => $this->workflow->disponibles($reserva, $user),
            // Al decidir, administración ve de antemano los choques que bloquearían la autorización.
            'traslapes' => $user->can('autorizar', $reserva) || $user->can('revisar', $reserva)
                ? $this->workflow->traslapes($reserva)
                : [],
        ]);
    }

    public function edit(Request $request, Reserva $reserva): Response
    {
        Gate::authorize('update', $reserva);

        $reserva->load('detalles');

        return Inertia::render('reservas/Form', [
            'reserva' => [
                ...$this->datosFormulario($reserva),
                'folio' => $reserva->folio,
                'detalles' => $reserva->detalles->map(fn (ReservaDetalle $detalle): array => $this->datosDetalle($detalle)),
            ],
            'opciones' => $this->opciones($request->user()),
        ]);
    }

    public function update(ReservaRequest $request, Reserva $reserva): RedirectResponse
    {
        Gate::authorize('update', $reserva);

        $this->reservas->actualizar($reserva, $request->user(), $request->datosReserva(), $request->datosDetalles());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reserva actualizada.']);

        return to_route('reservas.show', $reserva);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosFormulario(Reserva $reserva): array
    {
        return [
            'id' => $reserva->id,
            'licenciatura_id' => $reserva->licenciatura_id,
            'actividad_id' => $reserva->actividad_id,
            'nombre_actividad' => $reserva->nombre_actividad,
            'objetivo' => $reserva->objetivo,
            'tipo_formato' => $reserva->tipo_formato,
            'cuatrimestre_id' => $reserva->cuatrimestre_id,
            'asignatura_id' => $reserva->asignatura_id,
            'costo_alumno' => $reserva->costo_alumno,
            'promocion_redes' => $reserva->promocion_redes,
            'limite_min_usuarios' => $reserva->limite_min_usuarios,
            'limite_max_usuarios' => $reserva->limite_max_usuarios,
            'fecha_entrega_formato' => $reserva->fecha_entrega_formato?->toDateString(),
            'estado_id' => $reserva->estado_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDetalle(ReservaDetalle $detalle): array
    {
        return [
            'id' => $detalle->id,
            'espacio_id' => $detalle->espacio_id,
            'fecha' => $detalle->fecha->toDateString(),
            'hora_inicio' => ReservaDetalle::hhmm($detalle->hora_inicio),
            'hora_fin' => ReservaDetalle::hhmm($detalle->hora_fin),
            'cantidad_alumnos' => $detalle->cantidad_alumnos,
            'lugar_especifico' => $detalle->lugar_especifico,
            'descripcion_actividad' => $detalle->descripcion_actividad,
            'material_requerido' => $detalle->material_requerido,
            'observaciones' => $detalle->observaciones,
        ];
    }

    /**
     * Catálogos para el formulario, limitados a lo que el usuario puede usar.
     *
     * @return array<string, mixed>
     */
    private function opciones(User $user): array
    {
        $licenciaturas = $user->licenciaturas()->where('estatus', 'activa')->orderBy('nombre')->get(['licenciaturas.id', 'nombre']);

        return [
            'licenciaturas' => $licenciaturas->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre]),
            'actividades' => Actividad::query()->where('estatus', 'activa')->orderBy('nombre')->get(['id', 'nombre']),
            'cuatrimestres' => Cuatrimestre::query()->orderBy('numero')->get(['id', 'nombre']),
            'asignaturas' => Asignatura::query()
                ->whereIn('licenciatura_id', $licenciaturas->pluck('id'))
                ->where('estatus', 'activa')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'licenciatura_id', 'cuatrimestre_id']),
            'espacios' => Espacio::query()
                ->where('estatus', '!=', 'inactivo')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'ubicacion', 'capacidad', 'estatus']),
        ];
    }
}
