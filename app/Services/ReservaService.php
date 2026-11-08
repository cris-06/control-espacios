<?php

namespace App\Services;

use App\Models\HistorialReserva;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alta y edición de reservas con sus detalles. No cambia el estado:
 * eso lo hace exclusivamente ReservaWorkflowService.
 *
 * @phpstan-type DatosDetalle array{id?: int|null, espacio_id: int, fecha: string, hora_inicio: string, hora_fin: string, cantidad_alumnos?: int|null, lugar_especifico?: string|null, descripcion_actividad?: string|null, material_requerido?: string|null, observaciones?: string|null}
 */
class ReservaService
{
    public function __construct(private readonly ReservaWorkflowService $workflow) {}

    /**
     * Crea la reserva en borrador, asigna coordinador y registra 'creada' en el historial.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<DatosDetalle>  $detalles
     */
    public function crear(User $creador, array $datos, array $detalles): Reserva
    {
        return DB::transaction(function () use ($creador, $datos, $detalles): Reserva {
            $reserva = new Reserva($this->limpiar($datos));
            $reserva->usuario_creador_id = $creador->id;
            $reserva->setRelation('creador', $creador);
            $this->workflow->asignarCoordinador($reserva);
            $reserva->save();

            $reserva->folio = Reserva::folioPara($reserva->id);
            $reserva->save();

            $this->sincronizarDetalles($reserva, $detalles);

            $reserva->historial()->create([
                'usuario_id' => $creador->id,
                'accion' => HistorialReserva::CREADA,
            ]);

            return $reserva;
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  list<DatosDetalle>  $detalles
     */
    public function actualizar(Reserva $reserva, array $datos, array $detalles): Reserva
    {
        return DB::transaction(function () use ($reserva, $datos, $detalles): Reserva {
            $licenciaturaAnterior = $reserva->licenciatura_id;

            $reserva->fill($this->limpiar($datos));

            if ($reserva->licenciatura_id !== $licenciaturaAnterior) {
                $this->workflow->asignarCoordinador($reserva);
            }

            $reserva->save();
            $this->sincronizarDetalles($reserva, $detalles);

            return $reserva;
        });
    }

    /**
     * Actualiza los detalles con id, crea los nuevos y elimina los que ya no vienen.
     *
     * @param  list<DatosDetalle>  $detalles
     */
    private function sincronizarDetalles(Reserva $reserva, array $detalles): void
    {
        $conservados = [];

        foreach ($detalles as $datos) {
            $id = $datos['id'] ?? null;
            unset($datos['id']);

            $datos['hora_inicio'] = $this->hora($datos['hora_inicio']);
            $datos['hora_fin'] = $this->hora($datos['hora_fin']);

            $detalle = $id !== null
                ? ReservaDetalle::query()->where('reserva_id', $reserva->id)->findOrFail($id)
                : new ReservaDetalle(['estado_detalle' => ReservaDetalle::PENDIENTE]);

            $detalle->fill($datos);
            $detalle->reserva_id = $reserva->id;
            $detalle->save();

            $conservados[] = $detalle->id;
        }

        ReservaDetalle::query()
            ->where('reserva_id', $reserva->id)
            ->whereNotIn('id', $conservados)
            ->delete();
    }

    /**
     * La base exige objetivo NOT NULL: un borrador sin objetivo se guarda vacío.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function limpiar(array $datos): array
    {
        $datos['objetivo'] = trim((string) ($datos['objetivo'] ?? ''));

        return $datos;
    }

    /**
     * Normaliza a HH:MM:SS para que las comparaciones de horario sean consistentes.
     */
    private function hora(string $hora): string
    {
        return strlen($hora) === 5 ? $hora.':00' : $hora;
    }
}
