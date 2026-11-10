<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo estados_reserva. Los ids coinciden con los registros de la base.
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $descripcion
 * @property int $orden
 */
class EstadoReserva extends Model
{
    public const BORRADOR = 1;

    public const PENDIENTE_COORDINADOR = 2;

    public const CORRECCION = 3;

    public const PENDIENTE_ADMIN = 4;

    public const AUTORIZADA = 5;

    public const DENEGADA = 6;

    protected $table = 'estados_reserva';

    public $timestamps = false;

    protected $fillable = ['nombre', 'descripcion', 'orden'];
}
