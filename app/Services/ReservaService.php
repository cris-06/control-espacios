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
 * @phpstan-type DatosRequerimiento array{requerimiento_id: int, cantidad: int}
 * @phpstan-type DatosDetalle array{id?: int|null, espacio_id: int, fecha: string, hora_inicio: string, hora_fin: string, cantidad_alumnos?: int|null, lugar_especifico?: string|null, descripcion_actividad?: string|null, material_requerido?: string|null, observaciones?: string|null, docente_responsable_id?: int|null, requerimientos?: list<DatosRequerimiento>|null}
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
     * Guarda los cambios. Si quien edita es el coordinador (no el creador), se registra
     * 'editada_coordinador' en el historial con el resumen de lo que cambió.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<DatosDetalle>  $detalles
     */
    public function actualizar(Reserva $reserva, User $editor, array $datos, array $detalles): Reserva
    {
        return DB::transaction(function () use ($reserva, $editor, $datos, $detalles): Reserva {
            $licenciaturaAnterior = $reserva->licenciatura_id;

            $reserva->fill($this->limpiar($datos));
            $camposCambiados = array_keys($reserva->getDirty());

            if ($reserva->licenciatura_id !== $licenciaturaAnterior) {
                $this->workflow->asignarCoordinador($reserva);
            }

            $reserva->save();
            $detallesCambiaron = $this->sincronizarDetalles($reserva, $detalles);

            $esCoordinadorAjeno = $reserva->usuario_creador_id !== $editor->id
                && $editor->coordinaLicenciatura($licenciaturaAnterior);

            if ($esCoordinadorAjeno && ($camposCambiados !== [] || $detallesCambiaron)) {
                $reserva->historial()->create([
                    'usuario_id' => $editor->id,
                    'accion' => HistorialReserva::EDITADA_COORDINADOR,
                    'comentario' => $this->resumenCambios($camposCambiados, $detallesCambiaron),
                ]);
            }

            return $reserva;
        });
    }

    /**
     * @param  list<string>  $campos
     */
    private function resumenCambios(array $campos, bool $detallesCambiaron): string
    {
        $etiquetas = [
            'licenciatura_id' => 'licenciatura', 'actividad_id' => 'tipo de actividad',
            'nombre_actividad' => 'nombre de la actividad', 'objetivo' => 'objetivo',
            'tipo_formato' => 'tipo de formato', 'cuatrimestre_id' => 'cuatrimestre',
            'asignatura_id' => 'asignatura', 'costo_alumno' => 'costo por alumno',
            'promocion_redes' => 'promoción en redes', 'limite_min_usuarios' => 'mínimo de participantes',
            'limite_max_usuarios' => 'máximo de participantes',
        ];

        $partes = array_values(array_filter(array_map(fn (string $campo) => $etiquetas[$campo] ?? null, $campos)));

        if ($detallesCambiaron) {
            $partes[] = 'espacios y horarios';
        }

        return 'Cambió: '.implode(', ', $partes).'.';
    }

    /**
     * Actualiza los detalles con id, crea los nuevos y elimina los que ya no vienen.
     *
     * @param  list<DatosDetalle>  $detalles
     * @return bool si hubo algún cambio en los detalles
     */
    private function sincronizarDetalles(Reserva $reserva, array $detalles): bool
    {
        $conservados = [];
        $cambio = false;

        foreach ($detalles as $datos) {
            $id = $datos['id'] ?? null;
            // null (o ausente) = no se enviaron: se conservan los requerimientos guardados.
            $requerimientos = $datos['requerimientos'] ?? null;
            unset($datos['id'], $datos['requerimientos']);

            $datos['hora_inicio'] = $this->hora($datos['hora_inicio']);
            $datos['hora_fin'] = $this->hora($datos['hora_fin']);

            if ($id !== null) {
                $detalle = ReservaDetalle::query()->where('reserva_id', $reserva->id)->findOrFail($id);
            } else {
                $detalle = new ReservaDetalle;
                $detalle->estado_detalle = ReservaDetalle::PENDIENTE;
            }

            $detalle->fill($datos);
            $detalle->reserva_id = $reserva->id;
            $cambio = $cambio || ! $detalle->exists || $detalle->isDirty();
            $detalle->save();

            if ($requerimientos !== null) {
                $cambio = $this->sincronizarRequerimientos($detalle, $requerimientos) || $cambio;
            }

            $conservados[] = $detalle->id;
        }

        $eliminados = ReservaDetalle::query()
            ->where('reserva_id', $reserva->id)
            ->whereNotIn('id', $conservados)
            ->delete();

        return $cambio || $eliminados > 0;
    }

    /**
     * Deja en reserva_detalle_requerimientos exactamente los requerimientos pedidos.
     * El cambio se detecta comparando antes y después (el conteo de filas afectadas
     * de sync() difiere entre MySQL y SQLite).
     *
     * @param  list<array{requerimiento_id: int, cantidad: int}>  $requerimientos
     * @return bool si hubo algún cambio
     */
    private function sincronizarRequerimientos(ReservaDetalle $detalle, array $requerimientos): bool
    {
        $nuevos = [];

        foreach ($requerimientos as $requerimiento) {
            $nuevos[$requerimiento['requerimiento_id']] = $requerimiento['cantidad'];
        }

        $actuales = $detalle->requerimientos()
            ->pluck('reserva_detalle_requerimientos.cantidad', 'requerimientos.id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        ksort($nuevos);
        ksort($actuales);

        if ($nuevos === $actuales) {
            return false;
        }

        $detalle->requerimientos()->sync(array_map(fn (int $cantidad): array => ['cantidad' => $cantidad], $nuevos));

        return true;
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
