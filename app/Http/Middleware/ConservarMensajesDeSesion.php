<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesión de solo lectura para consultas JSON en segundo plano (p. ej. la disponibilidad en vivo
 * del formulario).
 *
 * Laravel guarda la sesión COMPLETA al final de cada petición. Una consulta en segundo plano que
 * corre a la vez que otras peticiones del mismo usuario, al guardarla:
 *  - consumiría los mensajes «flash» (errores de validación, avisos) de un guardado que la
 *    recarga del formulario todavía no ha mostrado;
 *  - pisaría lo que otra petición guardó mientras tanto (los errores, o el cierre de sesión);
 *  - podría restaurar una sesión que otra petición cerró, con los datos de autenticación.
 *
 * Esta consulta solo LEE la sesión (para autenticar al usuario) y no necesita guardar nada, así
 * que al terminar se sustituye el manejador por uno nulo: la sesión guardada queda exactamente
 * como la dejaron las demás peticiones. Los mensajes pendientes se conservan también en memoria
 * (reflash) para que esta petición no los marque como entregados.
 */
class ConservarMensajesDeSesion
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $sesion = $request->hasSession() ? $request->session() : null;

        if ($sesion instanceof Store) {
            $sesion->reflash();
            $sesion->setHandler(new NullSessionHandler);
        }

        return $response;
    }
}
