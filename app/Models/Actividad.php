<?php

namespace App\Models;

use Database\Factories\ActividadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de tipos de actividad (tabla `actividades`).
 *
 * @property int $id
 * @property string $nombre
 * @property string|null $estatus activa | eliminada
 */
class Actividad extends Model
{
    /** @use HasFactory<ActividadFactory> */
    use HasFactory;

    const CREATED_AT = 'fecha_registro';

    const UPDATED_AT = null;

    protected $table = 'actividades';

    protected $fillable = ['nombre', 'estatus'];
}
