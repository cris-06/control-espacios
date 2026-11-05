<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Espacio extends Model
{
    protected $table = 'espacios';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'descripcion',
        'ubicacion',
        'foto',
        'capacidad',
        'minimo_alumnos',
        'maximo_alumnos',
        'color_calendario',
        'estatus',
    ];

    protected $casts = [
        'capacidad' => 'integer',
        'minimo_alumnos' => 'integer',
        'maximo_alumnos' => 'integer',
    ];
}
