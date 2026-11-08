<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro de cada acción sobre una reserva (tabla `historial_reservas`).
 * `accion` es un ENUM de la base: solo se admiten las constantes de esta clase.
 *
 * @property int $id
 * @property int $reserva_id
 * @property int $usuario_id
 * @property string $accion
 * @property string|null $comentario
 * @property Carbon|null $fecha
 */
class HistorialReserva extends Model
{
    public const CREADA = 'creada';

    public const ENVIADA_COORDINADOR = 'enviada_coordinador';

    public const CORRECCION_SOLICITADA = 'correccion_solicitada';

    public const APROBADA_COORDINADOR = 'aprobada_coordinador';

    public const ENVIADA_ADMIN = 'enviada_admin';

    public const AUTORIZADA = 'autorizada';

    public const DENEGADA = 'denegada';

    public const CANCELADA = 'cancelada';

    const CREATED_AT = 'fecha';

    const UPDATED_AT = null;

    protected $table = 'historial_reservas';

    protected $fillable = ['usuario_id', 'accion', 'comentario'];

    protected function casts(): array
    {
        return [
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
}
