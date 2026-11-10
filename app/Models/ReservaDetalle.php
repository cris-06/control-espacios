<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 */
class ReservaDetalle extends Model
{
    public const PENDIENTE = 'pendiente';

    public const AUTORIZADA = 'autorizada';

    public const DENEGADA = 'denegada';

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
        ];
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
     * Hora en formato HH:MM (la base guarda HH:MM:SS).
     */
    public static function hhmm(string $hora): string
    {
        return substr($hora, 0, 5);
    }
}
