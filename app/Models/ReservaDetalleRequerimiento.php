<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un requerimiento pedido para un bloque, con su cantidad (tabla `reserva_detalle_requerimientos`).
 * Se usa para leer; las altas y cambios se hacen con ReservaDetalle::requerimientos()->sync().
 *
 * @property int $id
 * @property int $reserva_detalle_id
 * @property int $requerimiento_id
 * @property int $cantidad
 * @property-read Requerimiento $requerimiento
 */
class ReservaDetalleRequerimiento extends Model
{
    protected $table = 'reserva_detalle_requerimientos';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'reserva_detalle_id' => 'integer',
            'requerimiento_id' => 'integer',
            'cantidad' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Requerimiento, $this>
     */
    public function requerimiento(): BelongsTo
    {
        return $this->belongsTo(Requerimiento::class, 'requerimiento_id');
    }
}
