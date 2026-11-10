<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $nombre
 * @property int $licenciatura_id
 * @property int $cuatrimestre_id
 * @property string|null $estatus
 */
class Asignatura extends Model
{
    protected $table = 'asignaturas';

    public $timestamps = false;

    protected $fillable = ['nombre', 'licenciatura_id', 'cuatrimestre_id', 'estatus'];

    protected function casts(): array
    {
        return [
            'licenciatura_id' => 'integer',
            'cuatrimestre_id' => 'integer',
        ];
    }
}
