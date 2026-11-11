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
 * @property string $objetivo
 * @property int|null $cuatrimestre_id
 * @property int|null $asignatura_id
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

    // folio, fecha_creacion y fecha_entrega_formato no son asignables: las fija el servidor
    // (ReservaService::crear y el primer envío en ReservaWorkflowService::ejecutar).
    protected $fillable = [
        'actividad_id', 'licenciatura_id', 'tipo_formato', 'nombre_actividad', 'objetivo',
        'cuatrimestre_id', 'asignatura_id', 'costo_alumno', 'promocion_redes',
        'limite_min_usuarios', 'limite_max_usuarios',
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
            'estado_id' => 'integer',
            'costo_alumno' => 'decimal:2',
            'limite_min_usuarios' => 'integer',
            'limite_max_usuarios' => 'integer',
            'fecha_entrega_formato' => 'date:Y-m-d',
        ];
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
     * administración, las pendientes de autorizar.
     *
     * @param  Builder<Reserva>  $query
     */
    public function scopePorAtender(Builder $query, User $user): void
    {
        $query->visiblesPara($user);

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
}
