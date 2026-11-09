<?php

namespace App\Services;

use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ocupación de un espacio en una fecha y horario. Único lugar con la consulta de
 * traslape (la usan la autorización, los avisos al guardar y la consulta en vivo).
 *
 * Dos bloques se traslapan si comparten espacio y fecha y cada uno empieza antes
 * de que termine el otro (terminar a las 12:00 y empezar a las 12:00 no choca).
 */
class DisponibilidadEspacioService
{
    /** Reservas en revisión: avisan de un posible conflicto, pero no ocupan. */
    public const ESTADOS_PENDIENTES = [E::PENDIENTE_COORDINADOR, E::PENDIENTE_ADMIN];

    /**
     * Bloquea (SELECT … FOR UPDATE) las filas de los espacios dentro de la transacción
     * en curso. Dos autorizaciones que comparten un espacio se ejecutan así una tras
     * otra: la segunda espera a que la primera confirme y después vuelve a consultar
     * los choques. Se bloquean en orden de id para evitar interbloqueos.
     *
     * @param  array<int>  $espacioIds
     */
    public function bloquearEspacios(array $espacioIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $espacioIds)));

        if ($ids === []) {
            return;
        }

        sort($ids);

        Espacio::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
    }

    /**
     * Bloques de OTRAS reservas que chocan con el horario indicado. Siempre incluye
     * los autorizados; con $incluirPendientes, también los de reservas en revisión.
     * Con $bloquear la lectura es con bloqueo (FOR UPDATE): lee la versión más
     * reciente confirmada, no la instantánea de la transacción.
     *
     * Se usa JOIN con `reservas` y no whereHas() a propósito: en MySQL, un FOR UPDATE
     * no se extiende a las subconsultas, que leerían la instantánea (REPEATABLE READ)
     * y podrían ver como pendiente una reserva que otra transacción acaba de autorizar.
     * Con JOIN, las filas de ambas tablas se leen (y bloquean) en su última versión.
     *
     * @return Collection<int, ReservaDetalle> con `reserva` (id, folio, estado_id) cargada
     */
    public function choques(
        int $espacioId,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?int $excluirReservaId = null,
        bool $incluirPendientes = false,
        bool $bloquear = false,
    ): Collection {
        return ReservaDetalle::query()
            ->select('reserva_detalles.*', 'reservas.estado_id as reserva_estado_id')
            ->join('reservas', 'reservas.id', '=', 'reserva_detalles.reserva_id')
            ->where('reserva_detalles.espacio_id', $espacioId)
            ->whereDate('reserva_detalles.fecha', $fecha)
            ->where('reserva_detalles.hora_inicio', '<', $this->hora($horaFin))
            ->where('reserva_detalles.hora_fin', '>', $this->hora($horaInicio))
            ->when($excluirReservaId !== null, fn (Builder $q) => $q->where('reserva_detalles.reserva_id', '!=', $excluirReservaId))
            ->where(function (Builder $q) use ($incluirPendientes): void {
                $q->where(fn (Builder $q) => $q
                    ->where('reserva_detalles.estado_detalle', ReservaDetalle::AUTORIZADA)
                    ->where('reservas.estado_id', E::AUTORIZADA));

                if ($incluirPendientes) {
                    $q->orWhere(fn (Builder $q) => $q
                        ->where(fn (Builder $d) => $d
                            ->whereNull('reserva_detalles.estado_detalle')
                            ->orWhere('reserva_detalles.estado_detalle', ReservaDetalle::PENDIENTE))
                        ->whereIn('reservas.estado_id', self::ESTADOS_PENDIENTES));
                }
            })
            ->with('reserva:id,folio,estado_id')
            ->when($bloquear, fn (Builder $q) => $q->lockForUpdate())
            ->orderBy('reserva_detalles.hora_inicio')
            ->get();
    }

    /**
     * Choques en forma segura para mostrarlos a un usuario: solo horario y estado. El
     * folio aparece únicamente si puede ver esa reserva (Reserva::visiblesPara); nunca
     * se exponen creador, actividad, licenciatura ni observaciones.
     *
     * @param  Collection<int, ReservaDetalle>  $choques
     * @return list<array{hora_inicio: string, hora_fin: string, estado: 'autorizada'|'pendiente', folio: string|null}>
     */
    public function describir(Collection $choques, User $usuario): array
    {
        if ($choques->isEmpty()) {
            return [];
        }

        $visibles = Reserva::query()
            ->visiblesPara($usuario)
            ->whereIn('id', $choques->pluck('reserva_id')->unique())
            ->pluck('id')
            ->flip();

        $descritos = [];

        foreach ($choques as $choque) {
            // reserva_estado_id viene del JOIN de choques() (la fila leída, no la relación).
            $autorizada = (int) $choque->getAttribute('reserva_estado_id') === E::AUTORIZADA;

            $descritos[] = [
                'hora_inicio' => ReservaDetalle::hhmm($choque->hora_inicio),
                'hora_fin' => ReservaDetalle::hhmm($choque->hora_fin),
                'estado' => $autorizada ? 'autorizada' : 'pendiente',
                'folio' => $visibles->has($choque->reserva_id)
                    ? ($choque->reserva->folio ?? Reserva::folioPara($choque->reserva_id))
                    : null,
            ];
        }

        return $descritos;
    }

    /**
     * Avisos al guardar una solicitud. No impiden guardar el borrador: el choque con
     * una reserva autorizada se bloquea al autorizar, y con una pendiente solo se advierte.
     *
     * @return list<string>
     */
    public function avisosAlGuardar(Reserva $reserva, User $usuario): array
    {
        $detalles = $reserva->detalles()->with('espacio:id,nombre')->get()->values();
        $avisos = [];

        $bloques = array_values($detalles->map(fn (ReservaDetalle $d): array => [
            'espacio_id' => $d->espacio_id,
            'fecha' => $d->fecha->toDateString(),
            'hora_inicio' => $d->hora_inicio,
            'hora_fin' => $d->hora_fin,
        ])->all());

        foreach ($this->traslapesInternos($bloques) as [$i, $j]) {
            $avisos[] = sprintf('Espacio %d y espacio %d se traslapan en el mismo espacio y horario.', $i + 1, $j + 1);
        }

        foreach ($detalles as $i => $detalle) {
            $choques = $this->choques(
                $detalle->espacio_id,
                $detalle->fecha->toDateString(),
                $detalle->hora_inicio,
                $detalle->hora_fin,
                excluirReservaId: $reserva->id,
                incluirPendientes: true,
            );

            foreach ($this->describir($choques, $usuario) as $choque) {
                $con = $choque['folio'] !== null ? "la reserva {$choque['folio']}" : 'otra reserva';
                $espacio = $detalle->espacio->nombre ?? 'el espacio';

                $avisos[] = $choque['estado'] === 'autorizada'
                    ? sprintf('Espacio %d: %s ya está ocupado de %s a %s por %s autorizada; no podrá autorizarse en ese horario.', $i + 1, $espacio, $choque['hora_inicio'], $choque['hora_fin'], $con)
                    : sprintf('Espacio %d: %s tiene de %s a %s %s en revisión; podría haber conflicto.', $i + 1, $espacio, $choque['hora_inicio'], $choque['hora_fin'], $con);
            }
        }

        return $avisos;
    }

    /**
     * Pares de bloques de una misma solicitud que se pisan entre sí (índices de la lista).
     *
     * @param  list<array{espacio_id: int, fecha: string, hora_inicio: string, hora_fin: string}>  $bloques
     * @return list<array{0: int, 1: int}>
     */
    public function traslapesInternos(array $bloques): array
    {
        $pares = [];

        foreach ($bloques as $i => $a) {
            foreach (array_slice($bloques, $i + 1, null, true) as $j => $b) {
                if ((int) $a['espacio_id'] === (int) $b['espacio_id']
                    && $a['fecha'] === $b['fecha']
                    && $this->hora($a['hora_inicio']) < $this->hora($b['hora_fin'])
                    && $this->hora($b['hora_inicio']) < $this->hora($a['hora_fin'])) {
                    $pares[] = [$i, $j];
                }
            }
        }

        return $pares;
    }

    /**
     * Normaliza a HH:MM:SS (la base guarda HH:MM:SS; los formularios envían HH:MM).
     */
    private function hora(string $hora): string
    {
        return strlen($hora) === 5 ? $hora.':00' : $hora;
    }
}
