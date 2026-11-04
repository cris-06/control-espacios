<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asignatura extends Model
{
    protected $table = 'asignaturas';
    public $timestamps = false;

    protected $fillable = ['nombre', 'licenciatura_id', 'cuatrimestre_id', 'estatus'];

    public function licenciatura(): BelongsTo
    {
        return $this->belongsTo(Licenciatura::class, 'licenciatura_id');
    }

    public function cuatrimestre(): BelongsTo
    {
        return $this->belongsTo(Cuatrimestre::class, 'cuatrimestre_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'asignatura_id');
    }
}
