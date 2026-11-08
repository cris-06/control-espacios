<?php

namespace App\Models;

use Database\Factories\EspacioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $nombre
 * @property string|null $ubicacion
 * @property int|null $capacidad
 * @property string|null $color_calendario
 * @property string|null $estatus disponible | mantenimiento | inactivo
 */
class Espacio extends Model
{
    /** @use HasFactory<EspacioFactory> */
    use HasFactory;

    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = null;

    protected $table = 'espacios';

    protected $fillable = [
        'nombre', 'descripcion', 'ubicacion', 'foto', 'capacidad',
        'minimo_alumnos', 'maximo_alumnos', 'color_calendario', 'estatus',
    ];

    protected function casts(): array
    {
        return [
            'capacidad' => 'integer',
            'minimo_alumnos' => 'integer',
            'maximo_alumnos' => 'integer',
        ];
    }

    public function estaDisponible(): bool
    {
        return $this->estatus === 'disponible';
    }
}
