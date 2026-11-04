<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudCambioPassword extends Model
{
    protected $table = 'solicitudes_cambio_password';
    public $timestamps = false;

    protected $fillable = ['usuario_id', 'motivo', 'estado', 'respuesta_admin', 'fecha_respuesta'];

    protected $casts = [
        'fecha_solicitud' => 'datetime',
        'fecha_respuesta' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
