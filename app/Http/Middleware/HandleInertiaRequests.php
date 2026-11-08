<?php

namespace App\Http\Middleware;

use App\Http\Controllers\NotificacionController;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'rol' => $user?->rol?->only(['nombre', 'nivel']),
                // Solo para mostrar u ocultar opciones; el backend vuelve a validar cada acción.
                'permisos' => [
                    'administrar' => (bool) $user?->can('administrar'),
                    'verReportes' => (bool) $user?->can('ver-reportes'),
                ],
            ],
            // Tabla `notificaciones`: contador de no leídas y las más recientes para el menú.
            'notificaciones' => $user instanceof User ? fn (): array => [
                'noLeidas' => Notificacion::query()->where('usuario_id', $user->id)->noLeidas()->count(),
                'recientes' => Notificacion::query()
                    ->where('usuario_id', $user->id)
                    ->orderByDesc('fecha')
                    ->orderByDesc('id')
                    ->limit(8)
                    ->get()
                    ->map(fn (Notificacion $n): array => NotificacionController::fila($n)),
            ] : null,
            // Reservas que esperan una acción de este usuario (insignia de Solicitudes).
            'porAtender' => $user instanceof User
                ? fn (): int => Reserva::query()->porAtender($user)->count()
                : 0,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
