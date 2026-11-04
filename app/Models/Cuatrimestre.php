<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cuatrimestre extends Model
{
    protected $table = 'cuatrimestres';
    public $timestamps = false;

    protected $fillable = ['numero', 'nombre'];

    public function asignaturas(): HasMany
    {
        return $this->hasMany(Asignatura::class, 'cuatrimestre_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'cuatrimestre_id');
    }
}
