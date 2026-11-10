<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserva (tabla `reservas`). Por ahora solo lo necesario para la autorización;
 * el flujo de estados se implementa en ReservaWorkflowService (Fase 4).
 *
 * @property int $id
 * @property int $usuario_creador_id
 * @property int|null $coordinador_id
 * @property int $licenciatura_id
 * @property int $estado_id
 * @property string|null $observaciones_admin
 */
class Reserva extends Model
{
    const CREATED_AT = 'fecha_creacion';

    const UPDATED_AT = 'fecha_actualizacion';

    protected $table = 'reservas';

    protected function casts(): array
    {
        return [
            'usuario_creador_id' => 'integer',
            'coordinador_id' => 'integer',
            'licenciatura_id' => 'integer',
            'estado_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    /**
     * @return BelongsTo<Licenciatura, $this>
     */
    public function licenciatura(): BelongsTo
    {
        return $this->belongsTo(Licenciatura::class, 'licenciatura_id');
    }

    /**
     * @return BelongsTo<EstadoReserva, $this>
     */
    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoReserva::class, 'estado_id');
    }

    public function enEstado(int ...$estados): bool
    {
        return in_array($this->estado_id, $estados, true);
    }
}
