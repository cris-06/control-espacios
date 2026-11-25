<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Licenciatura que participa en una solicitud además de la principal (tabla
 * reserva_licenciaturas), con sus cuatrimestres y grupos. Es solo informativa: no concede
 * permisos ni interviene en la aprobación (eso depende de Reserva::$licenciatura_id).
 *
 * @property int $id
 * @property int $reserva_id
 * @property int $licenciatura_id
 * @property string $tipo 'participante' (del usuario) o 'invitada' (cualquier otra activa)
 * @property list<array{id: int, grupos: list<string>}>|null $cuatrimestres
 * @property int $orden
 */
class ReservaLicenciatura extends Model
{
    public const PARTICIPANTE = 'participante';

    public const INVITADA = 'invitada';

    public const TIPOS = [self::PARTICIPANTE, self::INVITADA];

    protected $table = 'reserva_licenciaturas';

    public $timestamps = false;

    protected $fillable = ['licenciatura_id', 'tipo', 'cuatrimestres', 'orden'];

    protected function casts(): array
    {
        return [
            'reserva_id' => 'integer',
            'licenciatura_id' => 'integer',
            'cuatrimestres' => 'array',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Reserva, $this>
     */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }

    /**
     * @return BelongsTo<Licenciatura, $this>
     */
    public function licenciatura(): BelongsTo
    {
        return $this->belongsTo(Licenciatura::class, 'licenciatura_id');
    }
}
