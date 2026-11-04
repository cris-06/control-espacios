<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Actividad extends Model
{
    protected $table = 'actividades';
    public $timestamps = false;

    protected $fillable = ['nombre', 'estatus'];

    protected $casts = [
        'fecha_registro' => 'datetime',
    ];

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'actividad_id');
    }
}
