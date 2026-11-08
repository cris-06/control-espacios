<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notificaciones propias del usuario (tabla `notificaciones`).
 * Cada usuario solo ve y marca las suyas: las ajenas responden 404.
 */
class NotificacionController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('notificaciones/Index', [
            'notificaciones' => Notificacion::query()
                ->where('usuario_id', $user->id)
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn (Notificacion $n): array => self::fila($n)),
        ]);
    }

    /**
     * Marca como leída y, si la notificación tiene reserva, la abre.
     */
    public function leer(Request $request, int $notificacion): RedirectResponse
    {
        $registro = $this->propia($request, $notificacion);

        if (! $registro->leida) {
            $registro->forceFill(['leida' => true])->save();
        }

        return $registro->reserva_id !== null && $registro->reserva()->exists()
            ? to_route('reservas.show', $registro->reserva_id)
            : back();
    }

    public function leerTodas(Request $request): RedirectResponse
    {
        Notificacion::query()
            ->where('usuario_id', $request->user()->id)
            ->noLeidas()
            ->update(['leida' => true]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function fila(Notificacion $notificacion): array
    {
        return [
            'id' => $notificacion->id,
            'titulo' => $notificacion->titulo,
            'mensaje' => $notificacion->mensaje,
            'tipo' => $notificacion->tipo ?? 'info',
            'leida' => (bool) $notificacion->leida,
            'reserva_id' => $notificacion->reserva_id,
            'fecha' => $notificacion->fecha?->toIso8601String(),
        ];
    }

    private function propia(Request $request, int $id): Notificacion
    {
        return Notificacion::query()
            ->where('usuario_id', $request->user()->id)
            ->findOrFail($id);
    }
}
