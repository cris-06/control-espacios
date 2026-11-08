<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $nombre
 * @property int $nivel
 * @property string|null $descripcion
 */
class Rol extends Model
{
    public const SUPERADMIN = 'superadmin';

    public const ADMIN = 'admin';

    public const COORDINADOR = 'coordinador';

    public const DOCENTE = 'docente';

    /** Niveles de roles.nivel. La autorización compara contra estos umbrales. */
    public const NIVEL_SUPERADMIN = 100;

    public const NIVEL_ADMIN = 90;

    public const NIVEL_COORDINADOR = 70;

    public const NIVEL_DOCENTE = 50;

    protected $table = 'roles';

    public $timestamps = false;

    protected $fillable = ['nombre', 'nivel', 'descripcion'];

    protected function casts(): array
    {
        return [
            'nivel' => 'integer',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'rol_id');
    }
}
