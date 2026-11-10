<?php

namespace App\Models;

use Database\Factories\LicenciaturaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $nombre
 * @property string|null $estatus
 */
class Licenciatura extends Model
{
    /** @use HasFactory<LicenciaturaFactory> */
    use HasFactory;

    protected $table = 'licenciaturas';

    public $timestamps = false;

    protected $fillable = ['nombre', 'estatus'];

    /**
     * Usuarios asignados (docentes y coordinadores) vía usuario_licenciaturas.
     *
     * @return BelongsToMany<User, $this>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_licenciaturas', 'licenciatura_id', 'usuario_id');
    }

    /**
     * Coordinadores activos asignados a esta licenciatura.
     *
     * @return BelongsToMany<User, $this>
     */
    public function coordinadores(): BelongsToMany
    {
        return $this->usuarios()
            ->where('usuarios.estatus', 'activo')
            ->whereHas('rol', fn (Builder $rol) => $rol->where('nombre', Rol::COORDINADOR));
    }
}
