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
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\ReservaDetalleRequerimiento;
use App\Models\Rol;
use App\Models\User;
use App\Services\AnticipacionReserva;
use App\Services\DisponibilidadEspacioService;
use App\Services\ReservaService;
use App\Services\ReservaWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private readonly AnticipacionReserva $anticipacion,
    ) {}

    public function create(Request $request): Response
    {
        Gate::authorize('create', Reserva::class);

        return Inertia::render('reservas/Form', [
            'reserva' => null,
            'opciones' => $this->opciones($request->user()),
            // Al crear aún no se sabe el destino (depende de la licenciatura elegida).
            'envio' => 'Guardar y enviar',
            'anticipacion' => $this->anticipacion(null),
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
            'creador.rol:id,nombre',
            'coordinador.rol:id,nombre',
            'licenciatura:id,nombre',
            'actividad:id,nombre',
            'cuatrimestre:id,nombre',
            'asignatura:id,nombre',
            'estado:id,nombre,descripcion',
            'detalles.espacio:id,nombre,ubicacion,capacidad',
            'detalles.docenteResponsable:id,nombre,apellido_paterno,apellido_materno',
            'detalles.requerimientosPedidos.requerimiento:id,nombre',
            'historial.usuario:id,nombre,apellido_paterno,apellido_materno,rol_id',
            'historial.usuario.rol:id,nombre',
        ]);

        $acciones = $this->workflow->disponibles($reserva, $user);
        $puedeEditar = $user->can('update', $reserva);

        return Inertia::render('reservas/Show', [
            'reserva' => [
                ...$this->datosFormulario($reserva),
                'folio' => $reserva->folio,
                'folio_institucional' => $reserva->folio_institucional,
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
                    'docente_responsable' => $detalle->docenteResponsable?->name,
                    'requerimientos_vista' => $detalle->requerimientosPedidos->map(fn (ReservaDetalleRequerimiento $pedido): array => [
                        'nombre' => $pedido->requerimiento->nombre,
                        'cantidad' => $pedido->cantidad,
                    ])->values(),
                ]),
                'historial' => $reserva->historial->map(fn (HistorialReserva $registro): array => [
                    'id' => $registro->id,
                    'accion' => $registro->accion,
                    'comentario' => $registro->comentario,
                    'usuario' => $registro->usuario->name,
                    'fecha' => $registro->fecha?->toIso8601String(),
                ]),
            ],
            // La corrección o decisión que sigue vigente según el estado actual (el resto queda en la línea de tiempo).
            'observacionVigente' => $this->observacionVigente($reserva),
            // Casillas de firma del Formato 10, generadas por el flujo (nunca capturadas a mano).
            'autorizaciones' => $this->autorizaciones($reserva),
            'puede' => [
                'editar' => $puedeEditar,
            ],
            'acciones' => $acciones,
            // Al decidir, administración ve de antemano los choques que bloquearían la autorización.
            'traslapes' => match (true) {
                $user->can('autorizar', $reserva) || $user->can('revisar', $reserva) => $this->workflow->traslapes($reserva, $user),
                // La creadora ve los choques de su solicitud mientras sigue en curso, sin folios ni datos de reservas ajenas.
                $reserva->usuario_creador_id === $user->id && $reserva->enEstado(
                    EstadoReserva::BORRADOR,
                    EstadoReserva::CORRECCION,
                    EstadoReserva::PENDIENTE_COORDINADOR,
                    EstadoReserva::PENDIENTE_ADMIN,
                ) => $this->disponibilidad->traslapesVisibles($reserva, $user),
                default => [],
            },
            // Requerimientos que el catálogo ya no respalda: solo para quien puede actuar ahora
            // (la creadora en borrador o corrección, coordinación al revisar, administración al autorizar).
            'advertencias' => $puedeEditar || $acciones !== []
                ? $this->workflow->advertenciasRequerimientos($reserva)
                : [],
        ]);
    }

    public function edit(Request $request, Reserva $reserva): Response
    {
        Gate::authorize('update', $reserva);

        $reserva->load([
            'detalles.requerimientosPedidos',
            'creador:id,nombre,apellido_paterno,apellido_materno,rol_id',
            'creador.rol:id,nombre',
            'coordinador:id,nombre,apellido_paterno,apellido_materno,rol_id',
            'coordinador.rol:id,nombre',
            'historial.usuario:id,nombre,apellido_paterno,apellido_materno,rol_id',
            'historial.usuario.rol:id,nombre',
        ]);

        return Inertia::render('reservas/Form', [
            'reserva' => [
                ...$this->datosFormulario($reserva),
                'folio' => $reserva->folio,
                'folio_institucional' => $reserva->folio_institucional,
                'detalles' => $reserva->detalles->map(fn (ReservaDetalle $detalle): array => $this->datosDetalle($detalle)),
            ],
            'opciones' => $this->opciones($request->user()),
            'envio' => $this->etiquetaEnvio($reserva, $request->user()),
            // Solo lectura: las mismas casillas que el detalle, generadas por el flujo.
            'autorizaciones' => $this->autorizaciones($reserva),
            'anticipacion' => $this->anticipacion($reserva),
        ]);
    }

    /**
     * Anticipación mínima calculada con el reloj del servidor (no el del navegador). Solo
     * informa al formulario: la regla la aplican ReservaService y ReservaWorkflowService.
     *
     * @return array{dias: int, fecha_minima: string, ya_enviada: bool}
     */
    private function anticipacion(?Reserva $reserva): array
    {
        return [
            'dias' => AnticipacionReserva::DIAS,
            'fecha_minima' => $this->anticipacion->fechaMinima()->toDateString(),
            'ya_enviada' => $reserva !== null && $this->anticipacion->yaEnviada($reserva),
        ];
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
            // Fechas automáticas (solo lectura): elaboración al crear; entrega al primer envío.
            'fecha_creacion' => $reserva->fecha_creacion?->toIso8601String(),
            'fecha_entrega_formato' => $reserva->fecha_entrega_formato?->toDateString(),
            'estado_id' => $reserva->estado_id,
        ];
    }

    /**
     * Las tres casillas de «Autorizaciones» del Formato 10 (docente responsable, coordinación
     * y administración), derivadas SOLO del estado actual y del historial ya cargado: nadie
     * las escribe. Solo cuenta lo ocurrido después de la última corrección solicitada, así
     * una aprobación anterior a una devolución nunca aparece como vigente. No escribe nada.
     *
     * @return list<array{clave: string, titulo: string, situacion: string, etiqueta: string, nombre: string|null, rol: string|null, fecha: string|null}>
     */
    private function autorizaciones(Reserva $reserva): array
    {
        $historial = $reserva->historial
            ->sortBy([
                fn (HistorialReserva $a, HistorialReserva $b): int => ($a->fecha?->getTimestamp() ?? 0) <=> ($b->fecha?->getTimestamp() ?? 0),
                fn (HistorialReserva $a, HistorialReserva $b): int => $a->id <=> $b->id,
            ])
            ->values();

        // Ciclo vigente: lo posterior a la última corrección solicitada (o todo, si nunca hubo).
        $ultimaCorreccion = $historial->last(fn (HistorialReserva $h): bool => $h->accion === HistorialReserva::CORRECCION_SOLICITADA);
        $ciclo = $ultimaCorreccion === null
            ? $historial
            : $historial->slice((int) $historial->search(fn (HistorialReserva $h): bool => $h->id === $ultimaCorreccion->id) + 1);
        $ultimo = fn (string ...$acciones): ?HistorialReserva => $ciclo->last(fn (HistorialReserva $h): bool => in_array($h->accion, $acciones, true));

        $rolDe = fn (?User $u): ?string => match ($u?->rol?->nombre) {
            Rol::ADMIN, Rol::SUPERADMIN => 'Administración',
            Rol::COORDINADOR => 'Coordinación',
            Rol::DOCENTE => 'Docente',
            default => null,
        };
        $casilla = fn (string $clave, string $titulo, string $situacion, string $etiqueta, ?HistorialReserva $registro = null, ?User $usuario = null): array => [
            'clave' => $clave,
            'titulo' => $titulo,
            'situacion' => $situacion,
            'etiqueta' => $etiqueta,
            'nombre' => ($registro->usuario ?? $usuario)?->name,
            'rol' => $rolDe($registro->usuario ?? $usuario),
            'fecha' => $registro?->fecha?->toIso8601String(),
        ];

        $estado = $reserva->estado_id;
        $decidida = in_array($estado, [EstadoReserva::PENDIENTE_ADMIN, EstadoReserva::AUTORIZADA, EstadoReserva::DENEGADA], true);
        $devueltaPor = fn (string $rol): ?HistorialReserva => $estado === EstadoReserva::CORRECCION
            && $ultimaCorreccion !== null
            && $rolDe($ultimaCorreccion->usuario) === $rol ? $ultimaCorreccion : null;

        // 1. Docente responsable (quien elaboró y entregó la solicitud).
        $envio = $ultimo(HistorialReserva::ENVIADA_COORDINADOR, HistorialReserva::ENVIADA_ADMIN);
        $docente = match (true) {
            $estado === EstadoReserva::BORRADOR => $casilla('docente', 'Docente responsable', 'pendiente', 'En elaboración, sin enviar', usuario: $reserva->creador),
            $estado === EstadoReserva::CORRECCION => $casilla('docente', 'Docente responsable', 'pendiente', 'Debe atender la corrección y reenviar', usuario: $reserva->creador),
            default => $casilla('docente', 'Docente responsable', 'completada', 'Entregó la solicitud', $envio, $reserva->creador),
        };

        // 2. Coordinación: aprobación vigente o envío directo de una solicitud propia.
        $aprobacion = $decidida ? $ultimo(HistorialReserva::APROBADA_COORDINADOR, HistorialReserva::ENVIADA_ADMIN) : null;
        $coordinacion = match (true) {
            $aprobacion !== null => $casilla(
                'coordinacion', 'Coordinación', 'completada',
                $aprobacion->accion === HistorialReserva::ENVIADA_ADMIN ? 'Solicitud propia enviada a administración' : 'Aprobada por coordinación',
                $aprobacion,
            ),
            $devueltaPor('Coordinación') !== null => $casilla('coordinacion', 'Coordinación', 'devuelta', 'Solicitó corrección', $devueltaPor('Coordinación')),
            $estado === EstadoReserva::PENDIENTE_COORDINADOR => $casilla('coordinacion', 'Coordinación', 'pendiente', 'En revisión', usuario: $reserva->coordinador),
            default => $casilla('coordinacion', 'Coordinación', 'pendiente', 'Pendiente', usuario: $reserva->coordinador),
        };

        // 3. Administración: la decisión final o su devolución.
        $administracion = match (true) {
            $estado === EstadoReserva::AUTORIZADA && ($r = $ultimo(HistorialReserva::AUTORIZADA)) !== null => $casilla('administracion', 'Autorización', 'completada', 'Autorizada', $r),
            $estado === EstadoReserva::DENEGADA && ($r = $ultimo(HistorialReserva::DENEGADA)) !== null => $casilla('administracion', 'Autorización', 'denegada', 'Denegada', $r),
            $devueltaPor('Administración') !== null => $casilla('administracion', 'Autorización', 'devuelta', 'Solicitó corrección', $devueltaPor('Administración')),
            $estado === EstadoReserva::PENDIENTE_ADMIN => $casilla('administracion', 'Autorización', 'pendiente', 'En revisión por administración'),
            $estado === EstadoReserva::AUTORIZADA => $casilla('administracion', 'Autorización', 'completada', 'Autorizada'),
            $estado === EstadoReserva::DENEGADA => $casilla('administracion', 'Autorización', 'denegada', 'Denegada'),
            default => $casilla('administracion', 'Autorización', 'pendiente', 'Pendiente'),
        };

        return [$docente, $coordinacion, $administracion];
    }

    /**
     * Observación o decisión vigente según el estado ACTUAL: la última corrección solicitada
     * si está en corrección, la autorización si está autorizada o la denegación si está
     * denegada. En cualquier otro estado no hay ninguna vigente (una corrección ya atendida
     * o un comentario de aprobación solo se ven en la línea de tiempo). Usa el historial ya
     * cargado y no escribe nada.
     *
     * @return array{tipo: string, comentario: string|null, autor: string|null, rol: string|null, fecha: string|null}|null
     */
    private function observacionVigente(Reserva $reserva): ?array
    {
        $tipo = match ($reserva->estado_id) {
            EstadoReserva::CORRECCION => HistorialReserva::CORRECCION_SOLICITADA,
            EstadoReserva::AUTORIZADA => HistorialReserva::AUTORIZADA,
            EstadoReserva::DENEGADA => HistorialReserva::DENEGADA,
            default => null,
        };

        if ($tipo === null) {
            return null;
        }

        // La más reciente de ese tipo, desempatando por id para que el resultado sea determinista.
        $registro = $reserva->historial
            ->filter(fn (HistorialReserva $h): bool => $h->accion === $tipo)
            ->sortBy([
                fn (HistorialReserva $a, HistorialReserva $b): int => ($a->fecha?->getTimestamp() ?? 0) <=> ($b->fecha?->getTimestamp() ?? 0),
                fn (HistorialReserva $a, HistorialReserva $b): int => $a->id <=> $b->id,
            ])
            ->last();

        if ($registro === null) {
            // Registros antiguos sin historial: solo el texto guardado, sin inventar autor ni fecha.
            return trim((string) $reserva->observaciones_admin) === '' ? null : [
                'tipo' => $tipo,
                'comentario' => $reserva->observaciones_admin,
                'autor' => null,
                'rol' => null,
                'fecha' => null,
            ];
        }

        return [
            'tipo' => $tipo,
            'comentario' => $registro->comentario,
            'autor' => $registro->usuario->name,
            'rol' => match ($registro->usuario->rol?->nombre) {
                Rol::ADMIN, Rol::SUPERADMIN => 'Administración',
                Rol::COORDINADOR => 'Coordinación',
                Rol::DOCENTE => 'Docente',
                default => null,
            },
            'fecha' => $registro->fecha?->toIso8601String(),
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
            'requerimientos' => $detalle->requerimientosPedidos->map(fn (ReservaDetalleRequerimiento $pedido): array => [
                'requerimiento_id' => $pedido->requerimiento_id,
                'cantidad' => $pedido->cantidad,
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
        $licenciaturaIds = array_values($licenciaturas->map(fn (Licenciatura $l): int => $l->id)->all());

        $espacios = Espacio::query()
            ->where('estatus', '!=', 'inactivo')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'ubicacion', 'capacidad', 'minimo_alumnos', 'maximo_alumnos', 'estatus']);
        $requerimientosPorEspacio = $this->requerimientosActivosPorEspacio(
            array_values($espacios->map(fn (Espacio $e): int => $e->id)->all()),
        );

        return [
            'licenciaturas' => $licenciaturas->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre]),
            'actividades' => Actividad::query()->where('estatus', 'activa')->orderBy('nombre')->get(['id', 'nombre']),
            'cuatrimestres' => Cuatrimestre::query()->orderBy('numero')->get(['id', 'nombre']),
            'asignaturas' => Asignatura::query()
                ->whereIn('licenciatura_id', $licenciaturas->pluck('id'))
                ->where('estatus', 'activa')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'licenciatura_id', 'cuatrimestre_id']),
            'espacios' => $espacios->map(fn (Espacio $e): array => [
                ...$e->only(['id', 'nombre', 'ubicacion', 'capacidad', 'minimo_alumnos', 'maximo_alumnos', 'estatus']),
                'requerimientos' => $requerimientosPorEspacio[$e->id] ?? [],
            ]),
            'docentes' => $this->docentesResponsables($licenciaturaIds),
        ];
    }

    /**
     * Requerimientos activos de cada espacio con su cantidad disponible
     * (espacio_requerimientos), ordenados por nombre. Una sola consulta.
     *
     * @param  list<int>  $espacioIds
     * @return array<int, list<array{id: int, nombre: string, cantidad_disponible: int}>>
     */
    private function requerimientosActivosPorEspacio(array $espacioIds): array
    {
        if ($espacioIds === []) {
            return [];
        }

        $filas = DB::table('espacio_requerimientos as er')
            ->join('requerimientos as r', 'r.id', '=', 'er.requerimiento_id')
            ->whereIn('er.espacio_id', $espacioIds)
            ->where('r.estatus', 'activo')
            ->orderBy('r.nombre')
            ->get(['er.espacio_id', 'r.id', 'r.nombre', 'er.cantidad_disponible']);

        $porEspacio = [];

        foreach ($filas as $fila) {
            $porEspacio[(int) $fila->espacio_id][] = [
                'id' => (int) $fila->id,
                'nombre' => (string) $fila->nombre,
                'cantidad_disponible' => (int) $fila->cantidad_disponible,
            ];
        }

        return $porEspacio;
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

        return array_values(User::query()
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
                'licenciatura_ids' => array_values($u->licenciaturas->map(fn (Licenciatura $l): int => $l->id)->all()),
            ])
            ->all());
    }
}
