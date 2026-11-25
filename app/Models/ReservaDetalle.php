<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un bloque de uso de espacio dentro de una reserva (tabla `reserva_detalles`).
 *
 * @property int $id
 * @property int $reserva_id
 * @property int $espacio_id
 * @property Carbon $fecha
 * @property string $hora_inicio HH:MM:SS
 * @property string $hora_fin HH:MM:SS
 * @property int|null $cantidad_alumnos
 * @property string|null $estado_detalle pendiente | autorizada | denegada | cancelada
 * @property int|null $detalle_origen_id bloque original del que es repetición (null si es original)
 * @property int|null $ocurrencia 0 = original de una reserva que se repite, 1… = repetición; null si no se repite
 */
class ReservaDetalle extends Model
{
    public const PENDIENTE = 'pendiente';

    public const AUTORIZADA = 'autorizada';

    public const DENEGADA = 'denegada';

    public const CANCELADA = 'cancelada';

    protected $table = 'reserva_detalles';

    public $timestamps = false;

    protected $fillable = [
        'espacio_id', 'fecha', 'hora_inicio', 'hora_fin', 'docente_responsable_id',
        'cantidad_alumnos', 'lugar_especifico', 'descripcion_actividad',
        'material_requerido', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'espacio_id' => 'integer',
            'cantidad_alumnos' => 'integer',
            'docente_responsable_id' => 'integer',
            // Los fija ReservaService al generar las repeticiones (no son asignables en masa).
            'detalle_origen_id' => 'integer',
            'ocurrencia' => 'integer',
        ];
    }

    /**
     * ¿Es una fecha generada por la repetición (no un bloque capturado en el formulario)?
     */
    public function esRepeticion(): bool
    {
        return $this->detalle_origen_id !== null;
    }

    /**
     * Fechas generadas a partir de este bloque cuando la reserva se repite.
     *
     * @return HasMany<ReservaDetalle, $this>
     */
    public function repeticiones(): HasMany
    {
        return $this->hasMany(self::class, 'detalle_origen_id')->orderBy('ocurrencia');
    }

    /**
     * @return BelongsTo<Reserva, $this>
     */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    /**
     * @return BelongsTo<Espacio, $this>
     */
    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'espacio_id');
    }

    /**
     * Docente que atiende este bloque (opcional).
     *
     * @return BelongsTo<User, $this>
     */
    public function docenteResponsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'docente_responsable_id');
    }

    /**
     * Requerimientos solicitados para este bloque, con la cantidad pedida.
     *
     * @return BelongsToMany<Requerimiento, $this>
     */
    public function requerimientos(): BelongsToMany
    {
        return $this->belongsToMany(Requerimiento::class, 'reserva_detalle_requerimientos', 'reserva_detalle_id', 'requerimiento_id')
            ->withPivot('id', 'cantidad');
    }

    /**
     * Las mismas filas de reserva_detalle_requerimientos como modelo propio, para leer
     * la cantidad pedida con tipos declarados (la escritura usa requerimientos()->sync()).
     *
     * @return HasMany<ReservaDetalleRequerimiento, $this>
     */
    public function requerimientosPedidos(): HasMany
    {
        return $this->hasMany(ReservaDetalleRequerimiento::class, 'reserva_detalle_id')->orderBy('id');
    }

    /**
     * Hora en formato HH:MM (la base guarda HH:MM:SS).
     */
    public static function hhmm(string $hora): string
    {
        return substr($hora, 0, 5);
    }
}
