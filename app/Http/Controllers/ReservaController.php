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
use App\Models\ReservaLicenciatura;
use App\Models\Rol;
use App\Models\User;
use App\Services\AnticipacionReserva;
use App\Services\DisponibilidadEspacioService;
use App\Services\ReservaService;
use App\Services\ReservaWorkflowService;
use App\Support\CasillasAutorizacion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
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
        $espacioId = $request->integer('espacio_id');

        $espacioPreseleccionado = $espacioId > 0
            && Espacio::query()
                ->whereKey($espacioId)
                ->where('estatus', 'disponible')
                ->exists()
            ? $espacioId
            : null;

        Gate::authorize('create', Reserva::class);

        // Fecha y horario elegidos en la tabla del día de Disponibilidad (solo rellenan el primer
        // bloque; al guardar se validan como cualquier otro dato).
        $bloque = Validator::make($request->only(['fecha', 'hora_inicio', 'hora_fin']), [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
        ]);

        return Inertia::render('reservas/Form', [
            'reserva' => null,
            'opciones' => $this->opciones($request->user()),
            // Al crear aún no se sabe el destino (depende de la licenciatura elegida).
            'envio' => 'Guardar y enviar',
            'anticipacion' => $this->anticipacion(null),
            'espacioPreseleccionado' => $espacioPreseleccionado,
            'bloquePreseleccionado' => $bloque->fails() ? null : $bloque->validated(),
        ]);
    }

    public function store(ReservaRequest $request): RedirectResponse
    {
        Gate::authorize('create', Reserva::class);

        $reserva = $this->reservas->crear($request->user(), $this->datosConCoordinador($request), $request->datosDetalles());

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
                'cuatrimestre' => implode(', ', $reserva->periodoCuatrimestral()) ?: null,
                'asignatura' => implode(', ', $reserva->asignaturas ?? []) ?: null,
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
            'autorizaciones' => CasillasAutorizacion::para($reserva),
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
            // Cancelar o enviar a edición una autorizada no revalida requerimientos: no cuentan.
            'advertencias' => $puedeEditar || $this->revalidaRequerimientos($acciones)
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
                // Solo los bloques capturados; las fechas generadas por la repetición se muestran
                // como «fechas_repeticion» de su bloque (no se editan una por una).
                'detalles' => $reserva->detalles
                    ->filter(fn (ReservaDetalle $detalle): bool => ! $detalle->esRepeticion())
                    ->map(fn (ReservaDetalle $detalle): array => [
                        ...$this->datosDetalle($detalle),
                        'fechas_repeticion' => $reserva->detalles
                            ->filter(fn (ReservaDetalle $r): bool => $r->detalle_origen_id === $detalle->id)
                            ->sortBy('ocurrencia')
                            ->map(fn (ReservaDetalle $r): string => $r->fecha->toDateString())
                            ->values()->all(),
                    ])->values(),
            ],
            'opciones' => $this->opciones($request->user(), $reserva),
            'envio' => $this->etiquetaEnvio($reserva, $request->user()),
            // Solo lectura: las mismas casillas que el detalle, generadas por el flujo.
            'autorizaciones' => CasillasAutorizacion::para($reserva),
            'anticipacion' => $this->anticipacion($reserva, $request->user()),
        ]);
    }

    /**
     * Anticipación mínima calculada con el reloj del servidor (no el del navegador). Solo
     * informa al formulario: la regla la aplican ReservaService y ReservaWorkflowService.
     *
     * excepcion_directivo es SOLO informativo para el formulario (oculta el aviso de 10 días):
     * la excepción la decide ReservaService con el usuario autenticado y su permiso.
     *
     * @return array{dias: int, fecha_minima: string, ya_enviada: bool, excepcion_directivo: bool}
     */
    private function anticipacion(?Reserva $reserva, ?User $user = null): array
    {
        $excepcion = $reserva !== null && $user !== null && $user->esAdministrador()
            && $reserva->enEstado(EstadoReserva::PENDIENTE_ADMIN) && $user->can('update', $reserva);

        return [
            'dias' => AnticipacionReserva::DIAS,
            'fecha_minima' => ($excepcion ? Date::today() : $this->anticipacion->fechaMinima())->toDateString(),
            'ya_enviada' => $reserva !== null && $this->anticipacion->yaEnviada($reserva),
            'excepcion_directivo' => $excepcion,
        ];
    }

    public function update(ReservaRequest $request, Reserva $reserva): RedirectResponse
    {
        Gate::authorize('update', $reserva);

        try {
            $this->reservas->actualizar($reserva, $request->user(), $this->datosConCoordinador($request), $request->datosDetalles(), $request->motivoEdicion());
        } catch (ValidationException $e) {
            // Edición del directivo: las comprobaciones de autorización numeran todos los bloques.
            throw ValidationException::withMessages($this->erroresPorBloque($reserva, $e->errors()));
        }

        return $this->despuesDeGuardar($request, $reserva, 'Reserva actualizada.');
    }

    /**
     * Datos de la reserva más el coordinador responsable elegido, que ReservaService aplica
     * aparte (no es asignable en masa).
     *
     * @return array<string, mixed>
     */
    private function datosConCoordinador(ReservaRequest $request): array
    {
        $datos = [...$request->datosReserva(), 'coordinador_id' => $request->coordinadorElegido()];
        $adicionales = $request->licenciaturasAdicionales();

        if ($adicionales !== null) {
            $datos['licenciaturas_adicionales'] = $adicionales;
        }

        return $datos;
    }

    /**
     * En una reserva que se repite, el flujo numera TODOS sus bloques (también las fechas
     * generadas), pero el formulario solo muestra los capturados. Los errores de detalles.N se
     * pasan al bloque capturado correspondiente, indicando la fecha si es una repetición; los
     * mensajes «Espacio N: …» de la lista general se reescriben igual.
     *
     * @param  array<string, list<string>>  $errores
     * @return array<string, list<string>>
     */
    private function erroresPorBloque(Reserva $reserva, array $errores): array
    {
        $todos = $reserva->detalles()->get(['id', 'fecha', 'hora_inicio', 'detalle_origen_id'])->values();

        if ($todos->every(fn (ReservaDetalle $d): bool => $d->detalle_origen_id === null)) {
            return $errores;
        }

        $capturados = $todos->filter(fn (ReservaDetalle $d): bool => $d->detalle_origen_id === null)->pluck('id')->values()->all();
        // Para cada posición del flujo: [posición en el formulario, fecha si es repetición].
        $destino = $todos->map(fn (ReservaDetalle $d): array => [
            (int) array_search($d->detalle_origen_id ?? $d->id, $capturados, true),
            $d->detalle_origen_id === null ? null : $d->fecha->format('d/m/Y'),
        ])->all();

        $resultado = [];

        foreach ($errores as $clave => $mensajes) {
            if (preg_match('/^detalles\.(\d+)\.(.+)$/', $clave, $m) && isset($destino[(int) $m[1]])) {
                [$j, $fecha] = $destino[(int) $m[1]];
                $clave = "detalles.$j.{$m[2]}";
                $mensajes = $fecha === null ? $mensajes : array_map(fn (string $t): string => "Repetición del {$fecha}: ".mb_lcfirst($t), $mensajes);
            } else {
                $mensajes = array_map(fn (string $t): string => preg_replace_callback(
                    '/^Espacio (\d+):/',
                    function (array $e) use ($destino): string {
                        [$j, $fecha] = $destino[(int) $e[1] - 1] ?? [(int) $e[1] - 1, null];

                        return 'Espacio '.($j + 1).($fecha === null ? '' : " (repetición del {$fecha})").':';
                    },
                    $t,
                ) ?? $t, $mensajes);
            }

            $resultado[$clave] = [...($resultado[$clave] ?? []), ...$mensajes];
        }

        return $resultado;
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
                // De vuelta al formulario: cada falla aparece bajo su campo (y la lista completa arriba).
                if ($user->can('update', $reserva->refresh())) {
                    Inertia::flash('toast', ['type' => 'warning', 'message' => 'Se guardó la reserva, pero no se pudo enviar. Corrige los campos marcados.']);

                    return to_route('reservas.edit', $reserva)->withErrors($this->erroresPorBloque($reserva, $e->errors()));
                }

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
            'cuatrimestres' => $reserva->cuatrimestres ?? [],
            'asignaturas' => $reserva->asignaturas ?? [],
            'costo_alumno' => $reserva->costo_alumno,
            'promocion_redes' => $reserva->promocion_redes,
            // Formato 10: datos capturados una vez por solicitud.
            'docente_responsable_id' => $reserva->docente_responsable_id,
            'coordinador_id' => $reserva->coordinador_id,
            'cantidad_total_alumnos' => $reserva->cantidad_total_alumnos,
            'descripcion_actividad' => $reserva->descripcion_actividad,
            'observaciones' => $reserva->observaciones,
            // Licenciaturas participantes e invitadas (solo informativas), en el orden capturado.
            'licenciaturas_adicionales' => $reserva->licenciaturasAdicionales()->with('licenciatura:id,nombre')->get()
                ->map(fn (ReservaLicenciatura $l): array => [
                    'licenciatura_id' => $l->licenciatura_id,
                    'nombre' => $l->licenciatura->nombre,
                    'tipo' => $l->tipo,
                    'cuatrimestres' => $l->cuatrimestres ?? [],
                ])->values()->all(),
            // «Esta reserva se repite» (null si no se repite).
            'repeticion' => $reserva->repeticion_frecuencia === null ? null : [
                'frecuencia' => $reserva->repeticion_frecuencia,
                'cantidad' => $reserva->repeticion_cantidad,
                'hasta' => $reserva->repeticion_hasta?->toDateString(),
            ],
            // Fechas automáticas (solo lectura): elaboración al crear; entrega al primer envío.
            'fecha_creacion' => $reserva->fecha_creacion?->toIso8601String(),
            'fecha_entrega_formato' => $reserva->fecha_entrega_formato?->toDateString(),
            'estado_id' => $reserva->estado_id,
        ];
    }

    /**
     * Alguna de las acciones disponibles vuelve a validar los requerimientos (enviar, aprobar,
     * autorizar, …). Las de gestión de una autorizada (cancelar, enviar a edición) no.
     *
     * @param  list<array{accion: string, etiqueta: string, destino: int, requiereObservacion: bool}>  $acciones
     */
    private function revalidaRequerimientos(array $acciones): bool
    {
        foreach ($acciones as $accion) {
            if (! in_array($accion['accion'], [ReservaWorkflowService::CANCELAR, ReservaWorkflowService::ENVIAR_EDICION], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Observación o decisión vigente según el estado ACTUAL: la última corrección solicitada
     * si está en corrección, la autorización si está autorizada o la denegación si está
     * denegada (o el motivo de la cancelación si está cancelada). En cualquier otro estado no hay ninguna vigente (una corrección ya atendida
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
            EstadoReserva::CANCELADA => HistorialReserva::CANCELADA,
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
    private function opciones(User $user, ?Reserva $reserva = null): array
    {
        // El directivo no tiene licenciaturas asignadas y no puede cambiar la de la reserva: solo
        // recibe esa (se muestra de solo lectura; mismo criterio que ReservaRequest y ReservaService).
        $licenciaturas = $user->esAdministrador() && $reserva !== null
            ? Licenciatura::query()->whereKey($reserva->licenciatura_id)->get(['id', 'nombre'])
            : $user->licenciaturas()->where('estatus', 'activa')->orderBy('nombre')->get(['licenciaturas.id', 'nombre']);
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
            'cuatrimestres' => Cuatrimestre::query()->orderBy('numero')->get(['id', 'numero', 'nombre']),
            // Cuántos cuatrimestres tiene cada licenciatura (la configura el superadministrador): el
            // Formato 10 ofrece del primero a ese número para la principal, participantes e invitadas.
            'cuatrimestres_por_licenciatura' => Licenciatura::query()->pluck('cantidad_cuatrimestres', 'id')
                ->map(fn (mixed $n): int => (int) $n)->all(),
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
            'coordinadores' => $this->coordinadoresResponsables($licenciaturaIds),
            // «Invitar licenciatura»: cualquier licenciatura activa (solo como participante
            // informativa; no concede permisos).
            'licenciaturas_invitables' => Licenciatura::query()->where('estatus', 'activa')->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Licenciatura $l): array => ['id' => $l->id, 'nombre' => $l->nombre])->values()->all(),
        ];
    }

    /**
     * Posibles coordinadores responsables: coordinadores activos de las licenciaturas del
     * usuario (los mismos que acepta ReservaRequest). Solo nombre.
     *
     * @param  list<int>  $licenciaturaIds
     * @return list<array{id: int, nombre: string, licenciatura_ids: list<int>}>
     */
    private function coordinadoresResponsables(array $licenciaturaIds): array
    {
        return $this->docentesResponsables($licenciaturaIds, [Rol::COORDINADOR]);
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
     * @param  list<string>  $roles
     * @return list<array{id: int, nombre: string, licenciatura_ids: list<int>}>
     */
    private function docentesResponsables(array $licenciaturaIds, array $roles = [Rol::DOCENTE, Rol::COORDINADOR]): array
    {
        if ($licenciaturaIds === []) {
            return [];
        }

        return array_values(User::query()
            ->where('estatus', 'activo')
            ->whereHas('rol', fn ($q) => $q->whereIn('nombre', $roles))
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
