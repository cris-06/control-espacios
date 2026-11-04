<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Espacio extends Model
{
    protected $table = 'espacios';
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
        'fecha_creacion' => 'datetime',
    ];

    public function requerimientos(): BelongsToMany
    {
        return $this->belongsToMany(Requerimiento::class, 'espacio_requerimientos', 'espacio_id', 'requerimiento_id')
            ->withPivot('id', 'cantidad_disponible');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ReservaDetalle::class, 'espacio_id');
    }
}
