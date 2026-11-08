<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si el usuario entra con una contraseña temporal (debe_cambiar_password = 1),
 * solo puede usar la pantalla para cambiarla y cerrar sesión.
 */
class EnsurePasswordIsChanged
{
    private const RUTAS_PERMITIDAS = ['password.cambiar', 'password.cambiar.update', 'logout'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->debe_cambiar_password && ! $request->routeIs(...self::RUTAS_PERMITIDAS)) {
            return redirect()->route('password.cambiar');
        }

        return $next($request);
    }
}
