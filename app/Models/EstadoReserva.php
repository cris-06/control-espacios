<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstadoReserva extends Model
{
    protected $table = 'estados_reserva';
    public $timestamps = false;

    protected $fillable = ['nombre', 'descripcion', 'orden'];

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'estado_id');
    }
}
