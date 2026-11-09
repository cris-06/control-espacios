<?php

namespace App\Http\Controllers;

use App\Exceptions\TransicionInvalida;
use App\Http\Requests\ReservaRequest;
use App\Models\Actividad;
use App\Models\Asignatura;
use App\Models\Cuatrimestre;
use App\Models\Espacio;
use App\Models\EstadoReserva;
use App\Models\HistorialReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\Rol;
use App\Models\User;
use App\Services\DisponibilidadEspacioService;
use App\Services\ReservaService;
use App\Services\ReservaWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
        private readonly DisponibilidadEspacioService $disponibilidad,
    ) {}

    public function create(Request $request): Response
    {
        Gate::authorize('create', Reserva::class);

        return Inertia::render('reservas/Form', [
            'reserva' => null,
            'opciones' => $this->opciones($request->user()),
            // Al crear aún no se sabe el destino (depende de la licenciatura elegida).
            'envio' => 'Guardar y enviar',
        ]);
    }

    public function store(ReservaRequest $request): RedirectResponse
    {
        Gate::authorize('create', Reserva::class);

        $reserva = $this->reservas->crear($request->user(), $request->datosReserva(), $request->datosDetalles());

        return $this->despuesDeGuardar($request, $reserva, "Reserva {$reserva->folio} guardada como borrador.");
    }

    public function show(Request $request, Reserva $reserva): Response
    {
        Gate::authorize('view', $reserva);

        /** @var User $user */
        $user = $request->user();

        $reserva->load([
            'creador:id,nombre,apellido_paterno,apellido_materno,correo,rol_id',
            'coordinador:id,nombre,apellido_paterno,apellido_materno,rol_id',
            'licenciatura:id,nombre',
            'actividad:id,nombre',
            'cuatrimestre:id,nombre',
            'asignatura:id,nombre',
            'estado:id,nombre,descripcion',
            'detalles.espacio:id,nombre,ubicacion,capacidad',
            'detalles.docenteResponsable:id,nombre,apellido_paterno,apellido_materno',
            'detalles.requerimientos:requerimientos.id,requerimientos.nombre',
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
                'detalles' => $reserva->detalles->map(fn(ReservaDetalle $detalle): array => [
                    ...$this->datosDetalle($detalle),
                    'espacio' => $detalle->espacio->only(['nombre', 'ubicacion', 'capacidad']),
                    'estado_detalle' => $detalle->estado_detalle,
                    'docente_responsable' => $detalle->docenteResponsable?->name,
                    'requerimientos_vista' => $detalle->requerimientos->map(fn ($r): array => [
                        'nombre' => $r->nombre,
                        'cantidad' => (int) $r->pivot->cantidad,
                    ])->values(),
                ]),
                'historial' => $reserva->historial->map(fn(HistorialReserva $registro): array => [
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

        $reserva->load('detalles.requerimientos:requerimientos.id');

        return Inertia::render('reservas/Form', [
            'reserva' => [
                ...$this->datosFormulario($reserva),
                'folio' => $reserva->folio,
                'detalles' => $reserva->detalles->map(fn(ReservaDetalle $detalle): array => $this->datosDetalle($detalle)),
            ],
            'opciones' => $this->opciones($request->user()),
            'envio' => $this->etiquetaEnvio($reserva, $request->user()),
        ]);
    }

    public function update(ReservaRequest $request, Reserva $reserva): RedirectResponse
    {
        Gate::authorize('update', $reserva);

        $this->reservas->actualizar($reserva, $request->user(), $request->datosReserva(), $request->datosDetalles());

        return $this->despuesDeGuardar($request, $reserva, 'Reserva actualizada.');
    }

    /**
     * Etiqueta de «Guardar y enviar» si quien edita puede enviar ahora la reserva
     * (mismo criterio que los botones del detalle), o null si no aplica.
     */
    private function etiquetaEnvio(Reserva $reserva, User $user): ?string
    {
        $envio = collect($this->workflow->disponibles($reserva, $user))
            ->firstWhere('accion', ReservaWorkflowService::ENVIAR);

        return $envio === null ? null : 'Guardar y '.mb_lcfirst($envio['etiqueta']);
    }

    /**
     * Ya guardada la reserva: si se pidió «Guardar y enviar», se envía con el flujo
     * existente (ReservaWorkflowService::ejecutar), en su propia transacción. Si el
     * envío no procede, el borrador ya quedó guardado y se explica el motivo en el
     * detalle. Guardar nunca se bloquea por disponibilidad: los choques son avisos.
     */
    private function despuesDeGuardar(ReservaRequest $request, Reserva $reserva, string $mensaje): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $avisos = $this->disponibilidad->avisosAlGuardar($reserva, $user);

        if ($request->boolean('enviar')) {
            try {
                $avisosEnvio = $this->workflow->ejecutar($reserva, $user, ReservaWorkflowService::ENVIAR);
            } catch (ValidationException $e) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => 'Se guardó la reserva, pero no se pudo enviar. Revisa los pendientes.']);

                return to_route('reservas.show', $reserva)->withErrors($e->errors());
            } catch (TransicionInvalida|AuthorizationException $e) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => 'Se guardó la reserva, pero no se pudo enviar.']);

                return to_route('reservas.show', $reserva)->withErrors(['transicion' => $e->getMessage()]);
            }

            $destino = $reserva->estado_id === EstadoReserva::PENDIENTE_ADMIN ? 'administración' : 'coordinación';
            $mensaje = "Reserva {$reserva->folio} guardada y enviada a {$destino}.";
            $avisos = array_values(array_unique([...$avisos, ...$avisosEnvio]));
        }

        Inertia::flash('toast', $avisos === []
            ? ['type' => 'success', 'message' => $mensaje]
            : ['type' => 'warning', 'message' => $mensaje.' Revisa la disponibilidad: '.implode(' ', $avisos)]);

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
            'docente_responsable_id' => $detalle->docente_responsable_id,
            'requerimientos' => $detalle->requerimientos->map(fn ($r): array => [
                'requerimiento_id' => $r->id,
                'cantidad' => (int) $r->pivot->cantidad,
            ])->values(),
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
            'licenciaturas' => $licenciaturas->map(fn($l) => ['id' => $l->id, 'nombre' => $l->nombre]),
            'actividades' => Actividad::query()->where('estatus', 'activa')->orderBy('nombre')->get(['id', 'nombre']),
            'cuatrimestres' => Cuatrimestre::query()->orderBy('numero')->get(['id', 'nombre']),
            'asignaturas' => Asignatura::query()
                ->whereIn('licenciatura_id', $licenciaturas->pluck('id'))
                ->where('estatus', 'activa')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'licenciatura_id', 'cuatrimestre_id']),
            'espacios' => Espacio::query()
                ->where('estatus', '!=', 'inactivo')
                ->with(['requerimientos' => fn ($q) => $q->where('requerimientos.estatus', 'activo')->orderBy('requerimientos.nombre')])
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'ubicacion', 'capacidad', 'minimo_alumnos', 'maximo_alumnos', 'estatus'])
                ->map(fn (Espacio $e): array => [
                    ...$e->only(['id', 'nombre', 'ubicacion', 'capacidad', 'minimo_alumnos', 'maximo_alumnos', 'estatus']),
                    'requerimientos' => $e->requerimientos->map(fn ($r): array => [
                        'id' => $r->id,
                        'nombre' => $r->nombre,
                        'cantidad_disponible' => (int) $r->pivot->cantidad_disponible,
                    ])->values(),
                ]),
            'docentes' => $this->docentesResponsables($licenciaturas->pluck('id')->all()),
        ];
    }

    /**
     * Posibles responsables de un bloque: docentes y coordinadores activos de las
     * licenciaturas del usuario. Solo nombre (sin correo ni teléfono).
     *
     * @param  list<int>  $licenciaturaIds
     * @return list<array{id: int, nombre: string, licenciatura_ids: list<int>}>
     */
    private function docentesResponsables(array $licenciaturaIds): array
    {
        if ($licenciaturaIds === []) {
            return [];
        }

        return User::query()
            ->where('estatus', 'activo')
            ->whereHas('rol', fn ($q) => $q->whereIn('nombre', [Rol::DOCENTE, Rol::COORDINADOR]))
            ->whereHas('licenciaturas', fn ($q) => $q->whereIn('licenciaturas.id', $licenciaturaIds))
            ->with(['licenciaturas' => fn ($q) => $q->whereIn('licenciaturas.id', $licenciaturaIds)->select('licenciaturas.id')])
            ->orderBy('nombre')
            ->orderBy('apellido_paterno')
            ->get(['id', 'nombre', 'apellido_paterno', 'apellido_materno'])
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'nombre' => $u->name,
                'licenciatura_ids' => $u->licenciaturas->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ])
            ->values()
            ->all();
    }
}
