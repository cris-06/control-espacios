<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Gestión de usuarios (tabla `usuarios`). Solo admin y superadmin.
 * Nadie gestiona a un usuario de nivel mayor al suyo, ni asigna un rol de nivel
 * mayor al suyo (eso último lo validan los FormRequest con Rol::asignablesPor()).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function create(User $user): bool
    {
        return $user->esAdministrador();
    }

    public function update(User $user, User $objetivo): Response
    {
        if (! $user->esAdministrador()) {
            return Response::deny('Solo administración puede gestionar usuarios.');
        }

        if ($objetivo->nivel() > $user->nivel()) {
            return Response::deny('No puedes modificar a un usuario con un rol de nivel mayor al tuyo.');
        }

        return Response::allow();
    }

    /**
     * Activar / inactivar. Nadie se inactiva a sí mismo (evita quedarse sin acceso).
     */
    public function cambiarEstatus(User $user, User $objetivo): Response
    {
        if ($user->is($objetivo)) {
            return Response::deny('No puedes cambiar el estatus de tu propia cuenta.');
        }

        return $this->update($user, $objetivo);
    }

    /**
     * Generar una nueva contraseña temporal. A uno mismo no: para eso está el perfil.
     */
    public function restablecerPassword(User $user, User $objetivo): Response
    {
        if ($user->is($objetivo)) {
            return Response::deny('Cambia tu propia contraseña desde Configuración → Seguridad.');
        }

        return $this->update($user, $objetivo);
    }
}
