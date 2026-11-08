<?php

namespace App\Services;

use App\Models\EstadoReserva as E;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Notificaciones (tabla `notificaciones`) que genera el flujo de reservas:
 * - Llega a coordinación      -> todos los coordinadores activos de la licenciatura.
 * - Llega a administración    -> todos los admin y superadmin activos.
 * - Corrección / autorización / denegación -> el creador.
 * Nunca se notifica a quien hizo la acción.
 */
class NotificacionService
{
    public function trasTransicion(Reserva $reserva, User $actor, ?string $observacion): void
    {
        $folio = $reserva->folio ?? Reserva::folioPara($reserva->id);
        $actividad = $reserva->nombre_actividad ?: 'sin nombre';
        $nota = $observacion !== null && $observacion !== '' ? "\nObservación: {$observacion}" : '';

        match ($reserva->estado_id) {
            E::PENDIENTE_COORDINADOR => $this->enviar(
                $this->coordinadores($reserva->licenciatura_id),
                $actor,
                $reserva,
                'Nueva solicitud por revisar',
                "{$actor->name} envió la reserva {$folio} ({$actividad}) para tu revisión.{$nota}",
                'info',
            ),
            E::PENDIENTE_ADMIN => $this->enviar(
                $this->administradores(),
                $actor,
                $reserva,
                'Reserva por autorizar',
                ($reserva->usuario_creador_id === $actor->id
                    ? "{$actor->name} (coordinación) envió su reserva {$folio} ({$actividad})."
                    : "{$actor->name} aprobó en coordinación la reserva {$folio} ({$actividad}).")
                    ." Espera tu decisión.{$nota}",
                'info',
            ),
            E::CORRECCION => $this->enviar(
                $this->creador($reserva),
                $actor,
                $reserva,
                'Tu reserva requiere corrección',
                "{$actor->name} pidió ajustes a la reserva {$folio} ({$actividad}).{$nota}",
                'warning',
            ),
            E::AUTORIZADA => $this->enviar(
                $this->creador($reserva),
                $actor,
                $reserva,
                'Reserva autorizada',
                "Tu reserva {$folio} ({$actividad}) fue autorizada.{$nota}",
                'success',
            ),
            E::DENEGADA => $this->enviar(
                $this->creador($reserva),
                $actor,
                $reserva,
                'Reserva denegada',
                "Tu reserva {$folio} ({$actividad}) fue denegada.{$nota}",
                'error',
            ),
            default => null,
        };
    }

    /**
     * @param  Collection<int, User>  $destinatarios
     */
    private function enviar(Collection $destinatarios, User $actor, Reserva $reserva, string $titulo, string $mensaje, string $tipo): void
    {
        $destinatarios
            ->reject(fn (User $usuario) => $usuario->is($actor))
            ->unique('id')
            ->each(fn (User $usuario) => Notificacion::create([
                'usuario_id' => $usuario->id,
                'reserva_id' => $reserva->id,
                'titulo' => $titulo,
                'mensaje' => mb_substr($mensaje, 0, 5000),
                'tipo' => $tipo,
            ]));
    }

    /**
     * @return Collection<int, User>
     */
    private function coordinadores(int $licenciaturaId): Collection
    {
        return Licenciatura::query()->find($licenciaturaId)?->coordinadores()->get() ?? collect();
    }

    /**
     * @return Collection<int, User>
     */
    private function administradores(): Collection
    {
        return User::query()
            ->where('estatus', 'activo')
            ->whereHas('rol', fn (Builder $rol) => $rol->where('nivel', '>=', Rol::NIVEL_ADMIN))
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function creador(Reserva $reserva): Collection
    {
        return User::query()->whereKey($reserva->usuario_creador_id)->where('estatus', 'activo')->get();
    }
}
