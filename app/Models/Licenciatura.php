<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Licenciatura extends Model
{
    protected $table = 'licenciaturas';
    public $timestamps = false;

    protected $fillable = ['nombre', 'estatus'];

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_licenciaturas', 'licenciatura_id', 'usuario_id');
    }

    public function asignaturas(): HasMany
    {
        return $this->hasMany(Asignatura::class, 'licenciatura_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'licenciatura_id');
    }
}
