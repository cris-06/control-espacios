<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Columnas de la tabla `requerimientos` (BD_Reservas_corregida.sql). La tabla la crea
 * el script SQL, no una migración, por eso se declaran aquí.
 *
 * @property int $id BIGINT UNSIGNED
 * @property string $nombre VARCHAR(100)
 * @property string $estatus ENUM('activo','eliminado')
 * @property Carbon|null $fecha_registro TIMESTAMP NULL (cast datetime)
 */
class Requerimiento extends Model
{
    protected $table = 'requerimientos';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'estatus',
    ];

    protected $casts = [
        'fecha_registro' => 'datetime',
    ];

    /**
     * Espacios que tienen asignado este requerimiento
     *
     * @return BelongsToMany<Espacio, $this>
     */
    public function espacios(): BelongsToMany
    {
        return $this->belongsToMany(
            Espacio::class,
            'espacio_requerimientos',
            'requerimiento_id',
            'espacio_id'
        )->withPivot('id', 'cantidad_disponible');
    }

    /**
     * Detalles de reservas asociados al requerimiento
     *
     * @return BelongsToMany<ReservaDetalle, $this>
     */
    public function reservaDetalles(): BelongsToMany
    {
        return $this->belongsToMany(
            ReservaDetalle::class,
            'reserva_detalle_requerimientos',
            'requerimiento_id',
            'reserva_detalle_id'
        )->withPivot('id', 'cantidad');
    }
}
