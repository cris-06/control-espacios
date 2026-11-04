<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Requerimiento extends Model
{
    protected $table = 'requerimientos';
    public $timestamps = false;

    protected $fillable = ['nombre', 'estatus'];

    protected $casts = [
        'fecha_registro' => 'datetime',
    ];

    public function espacios(): BelongsToMany
    {
        return $this->belongsToMany(Espacio::class, 'espacio_requerimientos', 'requerimiento_id', 'espacio_id')
            ->withPivot('id', 'cantidad_disponible');
    }

    public function reservaDetalles(): BelongsToMany
    {
        return $this->belongsToMany(ReservaDetalle::class, 'reserva_detalle_requerimientos', 'requerimiento_id', 'reserva_detalle_id')
            ->withPivot('id', 'cantidad');
    }
}
