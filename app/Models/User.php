<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'usuarios';

    const CREATED_AT = 'fecha_registro';
    const UPDATED_AT = null;

    protected $fillable = [
        'nombre',
        'apellido_paterno',
        'apellido_materno',
        'correo',
        'telefono',
        'password',
        'rol_id',
        'estatus',
        'debe_cambiar_password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'debe_cambiar_password' => 'boolean',
        'fecha_registro' => 'datetime',
    ];

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function licenciaturas(): BelongsToMany
    {
        return $this->belongsToMany(Licenciatura::class, 'usuario_licenciaturas', 'usuario_id', 'licenciatura_id');
    }

    public function reservasCreadas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'usuario_creador_id');
    }

    public function reservasCoordinadas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'coordinador_id');
    }

    public function detallesComoDocente(): HasMany
    {
        return $this->hasMany(ReservaDetalle::class, 'docente_responsable_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(HistorialReserva::class, 'usuario_id');
    }

    public function notificaciones(): HasMany
    {
        return $this->hasMany(Notificacion::class, 'usuario_id');
    }

    public function anuncios(): HasMany
    {
        return $this->hasMany(Anuncio::class, 'admin_id');
    }

    public function solicitudesCambioPassword(): HasMany
    {
        return $this->hasMany(SolicitudCambioPassword::class, 'usuario_id');
    }

    public function passwordResets(): HasMany
    {
        return $this->hasMany(PasswordReset::class, 'usuario_id');
    }
}