<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ReservaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reserva (tabla `reservas`). Los cambios de estado se hacen SOLO con
 * App\Services\ReservaWorkflowService; estado_id no es asignable en masa.
 *
 * @property int $id
 * @property int $usuario_creador_id
 * @property int|null $coordinador_id
 * @property int $actividad_id
 * @property int $licenciatura_id
 * @property string|null $nombre_actividad
 * @property string|null $folio
 * @property string|null $folio_institucional
 * @property int|null $docente_responsable_id responsable de la actividad completa
 * @property int|null $cantidad_total_alumnos alumnos distintos (no la suma de los espacios)
 * @property string|null $descripcion_actividad descripción general de la actividad
 * @property string|null $observaciones observaciones, anexos, jueces, invitados
 * @property string|null $repeticion_frecuencia diaria, semanal, mensual o anual; null si no se repite
 * @property int|null $repeticion_cantidad repeticiones después de la fecha original
 * @property CarbonInterface|null $repeticion_hasta fecha final (tiene prioridad sobre la cantidad)
 * @property string $objetivo
 * @property int|null $cuatrimestre_id anterior a la selección múltiple; el formulario usa $cuatrimestres
 * @property list<array{id: int, grupos: list<string>}>|null $cuatrimestres cuatrimestres marcados y sus grupos
 * @property int|null $asignatura_id anterior a la captura libre; el formulario usa $asignaturas
 * @property list<string>|null $asignaturas asignaturas escritas a mano
 * @property int $estado_id
 * @property string|null $observaciones_admin
 * @property string|null $tipo_formato
 * @property string|null $costo_alumno
 * @property string|null $promocion_redes
 * @property int|null $limite_min_usuarios
 * @property int|null $limite_max_usuarios
 * @property CarbonInterface|null $fecha_entrega_formato
 * @property CarbonInterface|null $fecha_creacion
 * @property CarbonInterface|null $fecha_actualizacion
 */
class Reserva extends Model
{
    /** @use HasFactory<ReservaFactory> */
    use HasFactory;

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $table = 'reservas';

    // folio, folio_institucional, fecha_creacion y fecha_entrega_formato no son asignables: las fija el servidor
    // (ReservaService::crear y el primer envío en ReservaWorkflowService::ejecutar).
    // coordinador_id tampoco: lo fija ReservaService (elegido entre los coordinadores de la
    // licenciatura) y lo confirma ReservaWorkflowService::asignarCoordinador.
    protected $fillable = [
        'actividad_id', 'licenciatura_id', 'tipo_formato', 'nombre_actividad', 'objetivo',
        'cuatrimestre_id', 'cuatrimestres', 'asignatura_id', 'asignaturas', 'costo_alumno', 'promocion_redes',
        'limite_min_usuarios', 'limite_max_usuarios',
        // Formato 10 (2026_11_24_000001): datos capturados por solicitud.
        'docente_responsable_id', 'cantidad_total_alumnos', 'descripcion_actividad', 'observaciones',
        // Repetición (2026_11_24_000002); las fechas las genera RecurrenciaReserva.
        'repeticion_frecuencia', 'repeticion_cantidad', 'repeticion_hasta',
    ];

    protected $attributes = [
        'estado_id' => EstadoReserva::BORRADOR,
        'objetivo' => '',
    ];

    protected function casts(): array
    {
        return [
            'usuario_creador_id' => 'integer',
            'coordinador_id' => 'integer',
            'actividad_id' => 'integer',
            'licenciatura_id' => 'integer',
            'cuatrimestre_id' => 'integer',
            'asignatura_id' => 'integer',
            'cuatrimestres' => 'array',
            'asignaturas' => 'array',
            'estado_id' => 'integer',
            'costo_alumno' => 'decimal:2',
            'limite_min_usuarios' => 'integer',
            'limite_max_usuarios' => 'integer',
            'docente_responsable_id' => 'integer',
            'cantidad_total_alumnos' => 'integer',
            'repeticion_cantidad' => 'integer',
            'repeticion_hasta' => 'date:Y-m-d',
            'fecha_entrega_formato' => 'date:Y-m-d',
        ];
    }

    /**
     * Licenciaturas participantes e invitadas (además de la principal), en el orden capturado.
     * Solo informativas: no conceden permisos.
     *
     * @return HasMany<ReservaLicenciatura, $this>
     */
    public function licenciaturasAdicionales(): HasMany
    {
        return $this->hasMany(ReservaLicenciatura::class, 'reserva_id')->orderBy('orden')->orderBy('id');
    }

    /**
     * Docente o coordinador responsable de la actividad completa (Formato 10). El responsable
     * de cada espacio está en ReservaDetalle::docenteResponsable.
     *
     * @return BelongsTo<User, $this>
     */
    public function docenteResponsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'docente_responsable_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    /**
     * @return BelongsTo<Licenciatura, $this>
     */
    public function licenciatura(): BelongsTo
    {
        return $this->belongsTo(Licenciatura::class, 'licenciatura_id');
    }

    /**
     * @return BelongsTo<EstadoReserva, $this>
     */
    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoReserva::class, 'estado_id');
    }

    /**
     * @return BelongsTo<Actividad, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class, 'actividad_id');
    }

    /**
     * @return BelongsTo<Cuatrimestre, $this>
     */
    public function cuatrimestre(): BelongsTo
    {
        return $this->belongsTo(Cuatrimestre::class, 'cuatrimestre_id');
    }

    /**
     * @return BelongsTo<Asignatura, $this>
     */
    public function asignatura(): BelongsTo
    {
        return $this->belongsTo(Asignatura::class, 'asignatura_id');
    }

    /**
     * Periodo cuatrimestral como se escribe en el Formato 10: cada grupo con su cuatrimestre
     * (1A, 1B, 3B, …) y, si un cuatrimestre no tiene grupos, su nombre. En orden de cuatrimestre.
     *
     * @return list<string>
     */
    public function periodoCuatrimestral(): array
    {
        return self::periodoDe($this->cuatrimestres);
    }

    /**
     * Mismo formato para cualquier lista de cuatrimestres con grupos (p. ej. la de una
     * licenciatura participante).
     *
     * @param  list<array{id: int, grupos: list<string>}>|null  $cuatrimestres
     * @return list<string>
     */
    public static function periodoDe(?array $cuatrimestres): array
    {
        if (empty($cuatrimestres)) {
            return [];
        }

        $grupos = collect($cuatrimestres)->mapWithKeys(fn (array $c): array => [(int) $c['id'] => $c['grupos']]);

        return array_values(Cuatrimestre::query()
            ->whereKey($grupos->keys()->all())
            ->orderBy('numero')
            ->get(['id', 'numero', 'nombre'])
            ->flatMap(fn (Cuatrimestre $c): array => $grupos[$c->id] === []
                ? [$c->nombre]
                : array_map(fn (string $grupo): string => $c->numero.$grupo, $grupos[$c->id]))
            ->all());
    }

    /**
     * @return HasMany<ReservaDetalle, $this>
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(ReservaDetalle::class, 'reserva_id')->orderBy('fecha')->orderBy('hora_inicio');
    }

    /**
     * @return HasMany<HistorialReserva, $this>
     */
    public function historial(): HasMany
    {
        return $this->hasMany(HistorialReserva::class, 'reserva_id')->orderBy('fecha')->orderBy('id');
    }

    /**
     * Reservas que el usuario puede ver (mismo criterio que ReservaPolicy::view):
     * administración ve todo lo enviado; el coordinador lo suyo y lo enviado de sus
     * licenciaturas; el docente solo lo suyo.
     *
     * @param  Builder<Reserva>  $query
     */
    public function scopeVisiblesPara(Builder $query, User $user): void
    {
        if ($user->esAdministrador()) {
            $query->where(fn (Builder $q) => $q
                ->where('estado_id', '!=', EstadoReserva::BORRADOR)
                ->orWhere('usuario_creador_id', $user->id));

            return;
        }

        if ($user->esCoordinador()) {
            $licenciaturas = $user->licenciaturas()->pluck('licenciaturas.id');

            $query->where(fn (Builder $q) => $q
                ->where('usuario_creador_id', $user->id)
                ->orWhere(fn (Builder $q) => $q
                    ->whereIn('licenciatura_id', $licenciaturas)
                    ->where('estado_id', '!=', EstadoReserva::BORRADOR)));

            return;
        }

        $query->where('usuario_creador_id', $user->id);
    }

    /**
     * Reservas que esperan una acción de este usuario: el docente, sus borradores y
     * correcciones; el coordinador, las de sus licenciaturas pendientes de revisión;
     * administración, las pendientes de autorizar; el superadmin, además, las pendientes de
     * coordinación (también puede revisarlas, ver ReservaPolicy::revisar).
     *
     * @param  Builder<Reserva>  $query
     */
    public function scopePorAtender(Builder $query, User $user): void
    {
        $query->visiblesPara($user);

        if ($user->esSuperadmin()) {
            $query->whereIn('estado_id', [EstadoReserva::PENDIENTE_COORDINADOR, EstadoReserva::PENDIENTE_ADMIN]);

            return;
        }

        if ($user->esAdministrador()) {
            $query->where('estado_id', EstadoReserva::PENDIENTE_ADMIN);

            return;
        }

        $query->where(function (Builder $q) use ($user): void {
            $q->where(fn (Builder $propias) => $propias
                ->where('usuario_creador_id', $user->id)
                ->whereIn('estado_id', [EstadoReserva::BORRADOR, EstadoReserva::CORRECCION]));

            if ($user->esCoordinador()) {
                $q->orWhere(fn (Builder $revision) => $revision
                    ->where('usuario_creador_id', '!=', $user->id)
                    ->where('estado_id', EstadoReserva::PENDIENTE_COORDINADOR));
            }
        });
    }

    public function enEstado(int ...$estados): bool
    {
        return in_array($this->estado_id, $estados, true);
    }

    /**
     * Folio legible derivado del id (se guarda al crear la reserva).
     */
    public static function folioPara(int $id): string
    {
        return sprintf('RES-%05d', $id);
    }

    /**
     * Folio institucional del Formato 10: {SIGLAS}-{AAAAMMDD}-{ID}, con el id en al menos
     * cinco dígitos (más largo no se recorta). La fecha es la de creación, en la zona del proyecto.
     */
    public static function folioInstitucionalPara(string $siglas, CarbonInterface $fechaCreacion, int $id): string
    {
        return sprintf('%s-%s-%05d', $siglas, $fechaCreacion->setTimezone(date_default_timezone_get())->format('Ymd'), $id);
    }
}
