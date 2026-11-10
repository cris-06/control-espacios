<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialReserva extends Model
{
    protected $table = 'historial_reservas';
    public $timestamps = false;

    protected $fillable = ['reserva_id', 'usuario_id', 'accion', 'comentario'];

    protected $casts = [
        'fecha' => 'datetime',
    ];

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
