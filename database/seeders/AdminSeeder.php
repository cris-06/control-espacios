<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Crea (si no existe) un usuario superadmin en `usuarios`.
 *
 * Lee config('auth.admin_inicial'), que viene del .env, para no guardar credenciales
 * en el repositorio: ADMIN_CORREO, ADMIN_PASSWORD, ADMIN_NOMBRE,
 * ADMIN_APELLIDO_PATERNO, ADMIN_APELLIDO_MATERNO
 *
 * Es idempotente: si ya existe un usuario con ese correo, no lo modifica.
 *   php artisan db:seed --class=AdminSeeder
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{correo: ?string, password: ?string, nombre: string, apellido_paterno: string, apellido_materno: ?string} $admin */
        $admin = config('auth.admin_inicial');

        if (! is_string($admin['correo']) || ! is_string($admin['password']) || $admin['correo'] === '' || $admin['password'] === '') {
            throw new RuntimeException('Define ADMIN_CORREO y ADMIN_PASSWORD en el .env antes de ejecutar AdminSeeder.');
        }

        $rolId = Rol::where('nombre', 'superadmin')->value('id')
            ?? throw new RuntimeException('No existe el rol "superadmin" en la tabla roles.');

        $user = User::firstOrCreate(
            ['correo' => strtolower($admin['correo'])],
            [
                'nombre' => $admin['nombre'],
                'apellido_paterno' => $admin['apellido_paterno'],
                'apellido_materno' => $admin['apellido_materno'],
                'password' => $admin['password'],
                'rol_id' => $rolId,
                'estatus' => 'activo',
            ],
        );

        $this->command?->info($user->wasRecentlyCreated
            ? "Superadmin creado: {$user->correo} (id {$user->id})"
            : "Ya existía un usuario con el correo {$user->correo}; no se modificó.");
    }
}
