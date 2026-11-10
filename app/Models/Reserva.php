<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reserva extends Model
{
    protected $table = 'reservas';

    // Esta tabla SÍ tiene fecha de creación y de actualización, con nombres propios
    const CREATED_AT = 'fecha_creacion';
    const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'usuario_creador_id',
        'coordinador_id',
        'actividad_id',
        'licenciatura_id',
        'tipo_formato',
        'nombre_actividad',
        'folio',
        'objetivo',
        'cuatrimestre_id',
        'asignatura_id',
        'costo_alumno',
        'promocion_redes',
        'limite_min_usuarios',
        'limite_max_usuarios',
        'fecha_entrega_formato',
        'estado_id',
        'observaciones_admin',
    ];

    protected $casts = [
        'fecha_entrega_formato' => 'date',
        'costo_alumno' => 'decimal:2',
        'fecha_creacion' => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class, 'actividad_id');
    }

    public function licenciatura(): BelongsTo
    {
        return $this->belongsTo(Licenciatura::class, 'licenciatura_id');
    }

    public function cuatrimestre(): BelongsTo
    {
        return $this->belongsTo(Cuatrimestre::class, 'cuatrimestre_id');
    }

    public function asignatura(): BelongsTo
    {
        return $this->belongsTo(Asignatura::class, 'asignatura_id');
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoReserva::class, 'estado_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ReservaDetalle::class, 'reserva_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(HistorialReserva::class, 'reserva_id');
    }
}
