<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Notificación del sistema (tabla propia `notificaciones`, no la de Laravel).
 *
 * @property int $id
 * @property int $usuario_id
 * @property int|null $reserva_id
 * @property string $titulo
 * @property string $mensaje
 * @property string|null $tipo info | success | warning | error
 * @property bool $leida
 * @property Carbon|null $fecha
 */
class Notificacion extends Model
{
    const CREATED_AT = 'fecha';

    const UPDATED_AT = null;

    protected $table = 'notificaciones';

    protected $fillable = ['usuario_id', 'reserva_id', 'titulo', 'mensaje', 'tipo'];

    protected $attributes = [
        'leida' => false,
        'tipo' => 'info',
    ];

    protected function casts(): array
    {
        return [
            'usuario_id' => 'integer',
            'reserva_id' => 'integer',
            'leida' => 'boolean',
            'fecha' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return BelongsTo<Reserva, $this>
     */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    /**
     * @param  Builder<Notificacion>  $query
     */
    public function scopeNoLeidas(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('leida', false)->orWhereNull('leida'));
    }
}
