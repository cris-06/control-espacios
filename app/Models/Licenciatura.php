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
 * @property string|null $siglas siglas oficiales (2 a 10 letras A-Z); null si aún no se registran
 * @property string|null $estatus
 */
class Licenciatura extends Model
{
    /** @use HasFactory<LicenciaturaFactory> */
    use HasFactory;

    protected $table = 'licenciaturas';

    public $timestamps = false;

    /** Siglas oficiales: de 2 a 10 letras mayúsculas, sin espacios ni otros signos. */
    public const PATRON_SIGLAS = '/^[A-Z]{2,10}$/';

    protected $fillable = ['nombre', 'siglas', 'estatus'];

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
