<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a ciertos roles (roles.nombre). Uso en rutas:
 *
 *   ->middleware('rol:admin,superadmin')
 *
 * Responde 403 si el usuario autenticado no tiene ninguno de esos roles.
 * Debe ir después de 'auth'.
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->tieneRol(...$roles),
            403,
            'No tienes permiso para acceder a esta sección.',
        );

        return $next($request);
    }
}
