<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Usuario autenticable, mapeado a la tabla existente `usuarios`.
 *
 * @property int $id
 * @property string $nombre
 * @property string $apellido_paterno
 * @property string|null $apellido_materno
 * @property string $correo
 * @property string|null $telefono
 * @property int $rol_id
 * @property string|null $estatus
 * @property-read string $name
 * @property-read string $email
 * @property Carbon|null $fecha_registro
 * @property Carbon|null $updated_at
 */
#[Fillable(['nombre', 'apellido_paterno', 'apellido_materno', 'correo', 'telefono', 'password', 'rol_id', 'estatus'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    const CREATED_AT = 'fecha_registro';

    const UPDATED_AT = 'updated_at';

    protected $table = 'usuarios';

    /**
     * Se incluyen en el JSON (p. ej. auth.user en Inertia) para el frontend.
     *
     * @var list<string>
     */
    protected $appends = ['name', 'email'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'debe_cambiar_password' => 'boolean',
        ];
    }

    /**
     * Nombre completo: nombre + apellido paterno + apellido materno.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => trim(implode(' ', array_filter([
            $this->nombre,
            $this->apellido_paterno,
            $this->apellido_materno,
        ]))));
    }

    /**
     * Alias de `correo` para el código de Laravel/Fortify que espera `email`.
     *
     * @return Attribute<string, never>
     */
    protected function email(): Attribute
    {
        return Attribute::get(fn (): string => $this->correo);
    }

    /**
     * @return BelongsTo<Rol, $this>
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
}
