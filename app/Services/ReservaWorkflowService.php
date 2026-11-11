<?php

namespace App\Services;

use App\Exceptions\TransicionInvalida;
use App\Models\Actividad;
use App\Models\Asignatura;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva as H;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\ReservaDetalleRequerimiento;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Único punto donde cambia reservas.estado_id.
 *
 * Cada transición: se ejecuta en una transacción con la fila bloqueada, se valida
 * contra TRANSICIONES (estado de origen + quién creó la reserva), se autoriza con
 * ReservaPolicy, exige observación cuando corresponde, aplica validaciones estrictas
 * o de traslape, actualiza los detalles, registra la acción en historial_reservas y
 * genera las notificaciones (NotificacionService), todo en la misma transacción.
 *
 * @phpstan-type Transicion array{accion: string, de: int, a: int, creador?: 'docente'|'coordinador', permiso: string, observacion: bool, estricta: bool, traslapes: 'avisar'|'bloquear'|null, detalles: string|null, historial: string}
 */
class ReservaWorkflowService
{
    public function __construct(
        private readonly NotificacionService $notificaciones,
        private readonly DisponibilidadEspacioService $disponibilidad,
        private readonly ValidacionDetalleReserva $validacionDetalle,
        private readonly AnticipacionReserva $anticipacion,
    ) {}

    public const ENVIAR = 'enviar';

    public const APROBAR = 'aprobar';

    public const SOLICITAR_CORRECCION = 'solicitar_correccion';

    public const AUTORIZAR = 'autorizar';

    public const DENEGAR = 'denegar';

    /**
     * Tabla de transiciones confirmada para la Fase 4. Todo lo que no esté aquí se rechaza.
     * `creador` distingue el envío de un docente (va a coordinación) del de un
     * coordinador de esa licenciatura (va directo a administración).
     *
     * @var list<Transicion>
     */
    private const TRANSICIONES = [
        // 1 y 2: el docente envía (o reenvía tras corrección) a su coordinador.
        ['accion' => self::ENVIAR, 'de' => E::BORRADOR, 'a' => E::PENDIENTE_COORDINADOR, 'creador' => 'docente', 'permiso' => 'enviar', 'observacion' => false, 'estricta' => false, 'traslapes' => null, 'detalles' => null, 'historial' => H::ENVIADA_COORDINADOR],
        ['accion' => self::ENVIAR, 'de' => E::CORRECCION, 'a' => E::PENDIENTE_COORDINADOR, 'creador' => 'docente', 'permiso' => 'enviar', 'observacion' => false, 'estricta' => false, 'traslapes' => null, 'detalles' => null, 'historial' => H::ENVIADA_COORDINADOR],
        // 1b y 2b: un coordinador que crea su propia reserva la envía directo a administración.
        ['accion' => self::ENVIAR, 'de' => E::BORRADOR, 'a' => E::PENDIENTE_ADMIN, 'creador' => 'coordinador', 'permiso' => 'enviar', 'observacion' => false, 'estricta' => true, 'traslapes' => 'avisar', 'detalles' => null, 'historial' => H::ENVIADA_ADMIN],
        ['accion' => self::ENVIAR, 'de' => E::CORRECCION, 'a' => E::PENDIENTE_ADMIN, 'creador' => 'coordinador', 'permiso' => 'enviar', 'observacion' => false, 'estricta' => true, 'traslapes' => 'avisar', 'detalles' => null, 'historial' => H::ENVIADA_ADMIN],
        // 3 y 4: revisión del coordinador.
        ['accion' => self::APROBAR, 'de' => E::PENDIENTE_COORDINADOR, 'a' => E::PENDIENTE_ADMIN, 'permiso' => 'revisar', 'observacion' => false, 'estricta' => true, 'traslapes' => 'avisar', 'detalles' => null, 'historial' => H::APROBADA_COORDINADOR],
        ['accion' => self::SOLICITAR_CORRECCION, 'de' => E::PENDIENTE_COORDINADOR, 'a' => E::CORRECCION, 'permiso' => 'revisar', 'observacion' => true, 'estricta' => false, 'traslapes' => null, 'detalles' => null, 'historial' => H::CORRECCION_SOLICITADA],
        // 5, 6 y 7: decisión de administración.
        ['accion' => self::AUTORIZAR, 'de' => E::PENDIENTE_ADMIN, 'a' => E::AUTORIZADA, 'permiso' => 'autorizar', 'observacion' => true, 'estricta' => true, 'traslapes' => 'bloquear', 'detalles' => ReservaDetalle::AUTORIZADA, 'historial' => H::AUTORIZADA],
        ['accion' => self::DENEGAR, 'de' => E::PENDIENTE_ADMIN, 'a' => E::DENEGADA, 'permiso' => 'autorizar', 'observacion' => true, 'estricta' => false, 'traslapes' => null, 'detalles' => ReservaDetalle::DENEGADA, 'historial' => H::DENEGADA],
        ['accion' => self::SOLICITAR_CORRECCION, 'de' => E::PENDIENTE_ADMIN, 'a' => E::CORRECCION, 'permiso' => 'autorizar', 'observacion' => true, 'estricta' => false, 'traslapes' => null, 'detalles' => ReservaDetalle::PENDIENTE, 'historial' => H::CORRECCION_SOLICITADA],
    ];

    private const ETIQUETAS = [
        self::ENVIAR => 'Enviar',
        self::APROBAR => 'Aprobar y enviar a administración',
        self::SOLICITAR_CORRECCION => 'Solicitar corrección',
        self::AUTORIZAR => 'Autorizar',
        self::DENEGAR => 'Denegar',
    ];

    /**
     * Ejecuta una acción sobre la reserva.
     *
     * @return list<string> avisos que no impiden la transición (p. ej. posibles traslapes)
     *
     * @throws TransicionInvalida si la acción no aplica al estado actual
     * @throws AuthorizationException si el usuario no tiene permiso
     * @throws ValidationException si falta la observación o la reserva no cumple las validaciones
     */
    public function ejecutar(Reserva $reserva, User $usuario, string $accion, ?string $observacion = null): array
    {
        $observacion = $observacion !== null ? trim($observacion) : '';

        return DB::transaction(function () use ($reserva, $usuario, $accion, $observacion): array {
            // Bloquea la fila: dos personas no pueden mover la misma reserva a la vez.
            $actual = Reserva::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();

            $transicion = $this->buscar($actual, $accion)
                ?? throw new TransicionInvalida($this->mensajeInvalida($actual, $accion));

            Gate::forUser($usuario)->authorize($transicion['permiso'], $actual);

            if ($transicion['observacion'] && $observacion === '') {
                throw ValidationException::withMessages([
                    'observacion' => 'Escribe una observación: es obligatoria para '.mb_strtolower(self::ETIQUETAS[$accion]).'.',
                ]);
            }

            if ($transicion['permiso'] === 'enviar') {
                // Anticipación mínima (Formato 10), con el estado aún sin cambiar: en el primer envío
                // todos los bloques; en un reenvío, que ninguna fecha haya pasado. Si falla, la
                // transacción se revierte sin estado, fecha de entrega, historial ni notificaciones.
                $this->anticipacion->validarEnvio($actual);
                $this->asignarCoordinador($actual);
                // Fecha de entrega del formato: la del PRIMER envío (a coordinación o, si la creó
                // coordinación, a administración). Un reenvío tras corrección la conserva. Se guarda
                // con el save() de esta transacción: si el envío se rechaza, no queda nada.
                $actual->fecha_entrega_formato ??= Date::today();
            }

            if ($transicion['estricta']) {
                $this->validarCompleta($actual);
            } elseif ($transicion['permiso'] === 'enviar') {
                // El envío a coordinación admite borradores incompletos, pero no requerimientos
                // que el catálogo ya no respalda (pudo cambiar desde que se guardaron).
                $this->validarRequerimientos($actual);
            }

            $avisos = [];

            if ($transicion['traslapes'] !== null) {
                // Al autorizar, se bloquean los espacios ANTES de volver a consultar los choques:
                // otra autorización sobre el mismo espacio espera a que esta confirme, y la
                // consulta (con FOR UPDATE) lee lo último confirmado dentro de esta transacción.
                if ($transicion['traslapes'] === 'bloquear') {
                    $this->disponibilidad->bloquearEspacios(
                        $actual->detalles()->pluck('espacio_id')->all(),
                    );
                }

                $traslapes = $this->traslapes($actual, $usuario);

                if ($traslapes !== [] && $transicion['traslapes'] === 'bloquear') {
                    throw $this->errores($traslapes);
                }

                $avisos = $traslapes;
            }

            $actual->estado_id = $transicion['a'];

            // Solo corrección, autorización y denegación (observación obligatoria) se guardan
            // en observaciones_admin; cualquier comentario queda además en el historial.
            if ($transicion['observacion']) {
                $actual->observaciones_admin = $observacion;
            }

            $actual->save();

            if ($transicion['detalles'] !== null) {
                ReservaDetalle::query()
                    ->where('reserva_id', $actual->id)
                    ->update(['estado_detalle' => $transicion['detalles']]);
            }

            $actual->historial()->create([
                'usuario_id' => $usuario->id,
                'accion' => $transicion['historial'],
                'comentario' => $observacion !== '' ? $observacion : null,
            ]);

            $this->notificaciones->trasTransicion($actual, $usuario, $observacion);

            $reserva->setRawAttributes($actual->getAttributes(), true);

            return $avisos;
        });
    }

    /**
     * Acciones que este usuario puede ejecutar ahora sobre la reserva (para la interfaz).
     *
     * @return list<array{accion: string, etiqueta: string, destino: int, requiereObservacion: bool}>
     */
    public function disponibles(Reserva $reserva, User $usuario): array
    {
        $disponibles = [];

        foreach ([self::ENVIAR, self::APROBAR, self::SOLICITAR_CORRECCION, self::AUTORIZAR, self::DENEGAR] as $accion) {
            $transicion = $this->buscar($reserva, $accion);

            if ($transicion === null || ! Gate::forUser($usuario)->allows($transicion['permiso'], $reserva)) {
                continue;
            }

            $disponibles[] = [
                'accion' => $accion,
                'etiqueta' => $accion === self::ENVIAR
                    ? ($transicion['a'] === E::PENDIENTE_ADMIN ? 'Enviar a administración' : 'Enviar a coordinación')
                    : self::ETIQUETAS[$accion],
                'destino' => $transicion['a'],
                'requiereObservacion' => $transicion['observacion'],
            ];
        }

        return $disponibles;
    }

    /**
     * Asigna reservas.coordinador_id según la licenciatura: el creador si la coordina,
     * o el primer coordinador activo de la licenciatura. No cambia uno ya asignado y válido.
     */
    public function asignarCoordinador(Reserva $reserva): void
    {
        $creador = $reserva->creador()->firstOrFail();

        if ($creador->coordinaLicenciatura($reserva->licenciatura_id)) {
            $reserva->coordinador_id = $creador->id;

            return;
        }

        $coordinadores = Licenciatura::query()->findOrFail($reserva->licenciatura_id)
            ->coordinadores()
            ->orderBy('usuarios.id')
            ->pluck('usuarios.id');

        if ($reserva->coordinador_id === null || ! $coordinadores->contains($reserva->coordinador_id)) {
            $reserva->coordinador_id = $coordinadores->first();
        }
    }

    /**
     * Validaciones estrictas que se exigen al enviar a administración.
     *
     * @throws ValidationException
     */
    public function validarCompleta(Reserva $reserva): void
    {
        $errores = [];

        $actividad = Actividad::query()->find($reserva->actividad_id);
        if ($actividad === null || $actividad->estatus !== 'activa') {
            $errores[] = 'Selecciona un tipo de actividad vigente.';
        }

        if (trim((string) $reserva->nombre_actividad) === '') {
            $errores[] = 'Falta el nombre de la actividad.';
        }

        if (trim($reserva->objetivo) === '') {
            $errores[] = 'Falta el objetivo de la actividad.';
        }

        if ($reserva->asignatura_id !== null) {
            $asignatura = Asignatura::query()->find($reserva->asignatura_id);
            if ($asignatura === null || $asignatura->licenciatura_id !== $reserva->licenciatura_id) {
                $errores[] = 'La asignatura no pertenece a la licenciatura de la reserva.';
            }
        }

        /** @var Collection<int, ReservaDetalle> $detalles */
        $detalles = $reserva->detalles()->with(['espacio', 'requerimientosPedidos'])->get();

        if ($detalles->isEmpty()) {
            $errores[] = 'Agrega al menos un espacio con fecha y horario.';
        }

        $hoy = Date::today()->toDateString();

        foreach ($detalles->values() as $i => $detalle) {
            $n = 'Espacio '.($i + 1);

            if ($detalle->espacio === null || ! $detalle->espacio->estaDisponible()) {
                $errores[] = "$n: el espacio no está disponible (inactivo o en mantenimiento).";
            }

            if ($detalle->fecha->toDateString() < $hoy) {
                $errores[] = "$n: la fecha ya pasó.";
            }

            if ($detalle->hora_inicio >= $detalle->hora_fin) {
                $errores[] = "$n: la hora de fin debe ser posterior a la de inicio.";
            }

            if ($detalle->cantidad_alumnos === null || $detalle->cantidad_alumnos < 1) {
                $errores[] = "$n: indica la cantidad de alumnos.";
            } elseif ($detalle->espacio?->capacidad !== null && $detalle->cantidad_alumnos > $detalle->espacio->capacidad) {
                $errores[] = "$n: {$detalle->cantidad_alumnos} alumnos superan la capacidad de {$detalle->espacio->nombre} ({$detalle->espacio->capacidad}).";
            }

            // El catálogo pudo cambiar desde que se guardó: se revalidan responsable y requerimientos.
            if ($detalle->docente_responsable_id !== null) {
                $error = $this->validacionDetalle->errorDocente($detalle->docente_responsable_id, $reserva->licenciatura_id);

                if ($error !== null) {
                    $errores[] = "$n: ".mb_lcfirst($error);
                }
            }

            array_push($errores, ...$this->erroresRequerimientos($detalle, $n));

            // Dos bloques de la misma reserva no pueden pisarse entre sí.
            foreach ($detalles->values()->slice($i + 1) as $j => $otro) {
                if ($this->seTraslapan($detalle, $otro)) {
                    $errores[] = "$n y espacio ".($j + 1).': se traslapan en el mismo espacio y horario.';
                }
            }
        }

        if ($errores !== []) {
            throw $this->errores($errores);
        }
    }

    /**
     * Validación ligera del envío a coordinación: solo los requerimientos ya pedidos,
     * contra el catálogo actual (activos, asignados al espacio y dentro de la cantidad
     * disponible). No exige que la reserva esté completa.
     *
     * @throws ValidationException
     */
    private function validarRequerimientos(Reserva $reserva): void
    {
        $problemas = $this->problemasRequerimientos(
            $reserva->detalles()->with('requerimientosPedidos')->get(),
            fn (ReservaDetalle $detalle, int $bloque): string => "Espacio $bloque",
        );

        if ($problemas !== []) {
            throw $this->errores(array_column($problemas, 'mensaje'));
        }
    }

    /**
     * Advertencias previas (solo lectura) sobre requerimientos pedidos que el catálogo
     * actual ya no respalda, para mostrarlas en el detalle antes de intentar una acción.
     * Reutiliza los bloques ya cargados y no modifica nada.
     *
     * @return list<array{detalle_id: int, bloque: int, mensaje: string}>
     */
    public function advertenciasRequerimientos(Reserva $reserva): array
    {
        $reserva->loadMissing(['detalles.espacio:id,nombre', 'detalles.requerimientosPedidos']);

        return $this->problemasRequerimientos(
            $reserva->detalles,
            fn (ReservaDetalle $detalle, int $bloque): string => $detalle->espacio !== null
                ? "Espacio $bloque ({$detalle->espacio->nombre})"
                : "Espacio $bloque",
        );
    }

    /**
     * Choques con reservas YA autorizadas en el mismo espacio, fecha y horario.
     *
     * La detección (choques() con bloqueo, uno por bloque) no depende de quién consulta:
     * hay exactamente un mensaje por choque. Solo el texto se adapta a $usuario: el folio
     * de la otra reserva aparece únicamente si puede verla (describir()); si no, se dice
     * "otra reserva autorizada", con su horario y nada más.
     *
     * @return list<string>
     */
    public function traslapes(Reserva $reserva, User $usuario): array
    {
        $mensajes = [];

        foreach ($reserva->detalles()->with('espacio:id,nombre')->get() as $detalle) {
            $choques = $this->disponibilidad->choques(
                $detalle->espacio_id,
                $detalle->fecha->toDateString(),
                $detalle->hora_inicio,
                $detalle->hora_fin,
                excluirReservaId: $reserva->id,
                bloquear: true,
            );

            foreach ($this->disponibilidad->describir($choques, $usuario) as $choque) {
                $mensajes[] = sprintf(
                    '%s el %s de %s a %s choca con %s (%s a %s).',
                    $detalle->espacio->nombre ?? 'El espacio',
                    $detalle->fecha->format('d/m/Y'),
                    ReservaDetalle::hhmm($detalle->hora_inicio),
                    ReservaDetalle::hhmm($detalle->hora_fin),
                    $choque['folio'] !== null ? "la reserva autorizada {$choque['folio']}" : 'otra reserva autorizada',
                    $choque['hora_inicio'],
                    $choque['hora_fin'],
                );
            }
        }

        return $mensajes;
    }

    /**
     * @return Transicion|null
     */
    private function buscar(Reserva $reserva, string $accion): ?array
    {
        $creadorCoordina = null;

        foreach (self::TRANSICIONES as $transicion) {
            if ($transicion['accion'] !== $accion || $transicion['de'] !== $reserva->estado_id) {
                continue;
            }

            if (isset($transicion['creador'])) {
                $creadorCoordina ??= (bool) $reserva->creador?->coordinaLicenciatura($reserva->licenciatura_id);

                if (($transicion['creador'] === 'coordinador') !== $creadorCoordina) {
                    continue;
                }
            }

            return $transicion;
        }

        return null;
    }

    /**
     * Problemas de requerimientos de cada bloque, numerados en el orden de la relación
     * (fecha y hora de inicio), el mismo en que se muestran.
     *
     * @param  Collection<int, ReservaDetalle>  $detalles
     * @param  Closure(ReservaDetalle, int): string  $prefijo
     * @return list<array{detalle_id: int, bloque: int, mensaje: string}>
     */
    private function problemasRequerimientos(Collection $detalles, Closure $prefijo): array
    {
        $problemas = [];

        foreach ($detalles->values() as $i => $detalle) {
            foreach ($this->erroresRequerimientos($detalle, $prefijo($detalle, $i + 1)) as $mensaje) {
                $problemas[] = ['detalle_id' => $detalle->id, 'bloque' => $i + 1, 'mensaje' => $mensaje];
            }
        }

        return $problemas;
    }

    /**
     * Requerimientos pedidos en un bloque que el catálogo actual ya no respalda.
     *
     * @return list<string>
     */
    private function erroresRequerimientos(ReservaDetalle $detalle, string $n): array
    {
        $pedidos = array_values($detalle->requerimientosPedidos
            ->map(fn (ReservaDetalleRequerimiento $pedido): array => ['requerimiento_id' => $pedido->requerimiento_id, 'cantidad' => $pedido->cantidad])
            ->all());

        return array_values(array_map(
            fn (string $mensaje): string => "$n: ".mb_lcfirst($mensaje),
            $this->validacionDetalle->erroresRequerimientos($detalle->espacio_id, $pedidos),
        ));
    }

    /**
     * Un mensaje por clave (reserva.0, reserva.1, …): Inertia solo comparte el primer
     * mensaje de cada clave y aquí interesa mostrarlos todos.
     *
     * @param  list<string>  $mensajes
     */
    private function errores(array $mensajes): ValidationException
    {
        $porClave = [];

        foreach ($mensajes as $i => $mensaje) {
            $porClave["reserva.$i"] = $mensaje;
        }

        return ValidationException::withMessages($porClave);
    }

    private function seTraslapan(ReservaDetalle $a, ReservaDetalle $b): bool
    {
        return $a->espacio_id === $b->espacio_id
            && $a->fecha->toDateString() === $b->fecha->toDateString()
            && $a->hora_inicio < $b->hora_fin
            && $b->hora_inicio < $a->hora_fin;
    }

    private function mensajeInvalida(Reserva $reserva, string $accion): string
    {
        $estado = E::query()->find($reserva->estado_id)->nombre ?? (string) $reserva->estado_id;
        $etiqueta = self::ETIQUETAS[$accion] ?? $accion;

        return "No se puede «{$etiqueta}» una reserva en estado «{$estado}».";
    }
}
