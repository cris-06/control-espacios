<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReservaDetalle extends Model
{
    protected $table = 'reserva_detalles';
    public $timestamps = false;

    protected $fillable = [
        'reserva_id',
        'espacio_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'docente_responsable_id',
        'cantidad_alumnos',
        'lugar_especifico',
        'descripcion_actividad',
        'material_requerido',
        'observaciones',
        'estado_detalle',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'espacio_id');
    }

    public function docenteResponsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'docente_responsable_id');
    }

    public function requerimientos(): BelongsToMany
    {
        return $this->belongsToMany(Requerimiento::class, 'reserva_detalle_requerimientos', 'reserva_detalle_id', 'requerimiento_id')
            ->withPivot('id', 'cantidad');
    }
}
