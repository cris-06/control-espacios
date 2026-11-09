<?php

namespace App\Services;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de integridad de un bloque (reserva_detalles) que dependen de otros
 * datos: el docente responsable y los requerimientos del espacio. Se usan al
 * guardar (ReservaRequest) y se vuelven a aplicar en las validaciones estrictas,
 * porque el catálogo puede cambiar entre el guardado y el envío.
 */
class ValidacionDetalleReserva
{
    /**
     * El responsable debe ser un docente o coordinador activo de la licenciatura.
     */
    public function errorDocente(int $docenteId, int $licenciaturaId): ?string
    {
        $docente = User::query()->with('rol')->find($docenteId);

        if ($docente === null || ! $docente->estaActivo() || ! $docente->tieneRol(Rol::DOCENTE, Rol::COORDINADOR)) {
            return 'El docente responsable debe ser un docente o coordinador activo.';
        }

        if (! $docente->perteneceALicenciatura($licenciaturaId)) {
            return 'El docente responsable no pertenece a la licenciatura de la reserva.';
        }

        return null;
    }

    /**
     * Solo requerimientos activos asignados al espacio y sin superar su cantidad disponible.
     *
     * @param  list<array{requerimiento_id: int, cantidad: int}>  $requerimientos
     * @return array<int, string> mensaje por posición del requerimiento en la lista
     */
    public function erroresRequerimientos(int $espacioId, array $requerimientos): array
    {
        if ($requerimientos === []) {
            return [];
        }

        $delEspacio = DB::table('espacio_requerimientos as er')
            ->join('requerimientos as r', 'r.id', '=', 'er.requerimiento_id')
            ->where('er.espacio_id', $espacioId)
            ->whereIn('er.requerimiento_id', array_column($requerimientos, 'requerimiento_id'))
            ->get(['er.requerimiento_id', 'er.cantidad_disponible', 'r.nombre', 'r.estatus'])
            ->keyBy(fn (object $fila): int => (int) $fila->requerimiento_id);

        $errores = [];

        foreach ($requerimientos as $j => $pedido) {
            $fila = $delEspacio->get($pedido['requerimiento_id']);

            if ($fila === null) {
                $errores[$j] = 'El requerimiento seleccionado no está disponible en este espacio.';
            } elseif ($fila->estatus !== 'activo') {
                $errores[$j] = "El requerimiento «{$fila->nombre}» ya no está vigente.";
            } elseif ($pedido['cantidad'] > (int) $fila->cantidad_disponible) {
                $errores[$j] = "Solo hay {$fila->cantidad_disponible} de «{$fila->nombre}» en este espacio.";
            }
        }

        return $errores;
    }
}
