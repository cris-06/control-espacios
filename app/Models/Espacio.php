<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Espacio extends Model
{
    use HasFactory;

    protected $table = 'espacios';

    protected $primaryKey = 'id';

    // Nombre de la columna de fecha de creación
    const CREATED_AT = 'fecha_creacion';

    // La tabla no tiene columna updated_at
    const UPDATED_AT = null;

    // Campos que se pueden registrar y modificar
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

    // Conversión de los tipos de datos
    protected $casts = [
        'capacidad' => 'integer',
        'minimo_alumnos' => 'integer',
        'maximo_alumnos' => 'integer',
        'fecha_creacion' => 'datetime',
    ];

    // Relación con los requerimientos asignados
    public function requerimientos(): BelongsToMany
    {
        return $this->belongsToMany(
            Requerimiento::class,
            'espacio_requerimientos',
            'espacio_id',
            'requerimiento_id'
        )->withPivot('id', 'cantidad_disponible');
    }

    // Relación con los detalles de las reservas
    public function detalles(): HasMany
    {
        return $this->hasMany(
            ReservaDetalle::class,
            'espacio_id'
        );
    }

    // Método utilizado por el módulo de autenticación/reservas
    public function estaDisponible(): bool
    {
        return $this->estatus === 'disponible';
    }

    // Método utilizado por nuestro módulo de espacios
    public function puedeReservarse(): bool
    {
        return $this->estaDisponible();
    }
}
