<?php

namespace App\Services;

use App\Models\Reserva;
use App\Models\ReservaDetalle;
use Carbon\CarbonImmutable;

/**
 * «Esta reserva se repite»: genera las fechas de las repeticiones de cada bloque.
 *
 * Reglas:
 *  - repeticion_cantidad = ocasiones ADICIONALES después de la fecha original
 *    (1 dic + semanal × 4 → 1, 8, 15, 22 y 29 dic: cinco fechas en total).
 *  - Si hay fecha de fin, tiene prioridad sobre la cantidad: se generan las fechas del patrón
 *    hasta ese día, incluido si coincide.
 *  - Cada fecha se calcula desde la fecha ORIGINAL (no desde la anterior), así no se acumulan
 *    desplazamientos: mensual y anual sin desbordar el mes (31 ene → 28/29 feb → 31 mar;
 *    29 feb 2028 → 28 feb 2029 → … → 29 feb 2032).
 *  - Con varios bloques, cada uno se repite desde su propia fecha: se conservan las diferencias
 *    de fechas entre bloques, y cada repetición copia espacio, horario, responsable, alumnos,
 *    requerimientos y textos de su bloque.
 *
 * Límites de seguridad (también en ReservaRequest):
 *  - MAX_REPETICIONES repeticiones por bloque (más la fecha original);
 *  - la fecha de fin a lo más MAX_DIAS_HASTA días después de la primera fecha;
 *  - MAX_BLOQUES bloques en total en la solicitud (originales + repeticiones).
 *
 * Las fechas generadas son bloques normales de reserva_detalles (enlazados con
 * detalle_origen_id y ocurrencia), de modo que pasan por las mismas validaciones, choques,
 * bloqueos, anticipación y autorización que cualquier bloque.
 *
 * @phpstan-import-type DatosDetalle from ReservaService
 *
 * @phpstan-type Repeticion array{frecuencia: string, cantidad: int|null, hasta: string|null}
 * @phpstan-type BloqueExpandido array{datos: DatosDetalle, origen: int|null, ocurrencia: int|null}
 */
class RecurrenciaReserva
{
    public const FRECUENCIAS = ['diaria', 'semanal', 'mensual', 'anual'];

    public const MAX_REPETICIONES = 52;

    public const MAX_DIAS_HASTA = 366;

    public const MAX_BLOQUES = 100;

    /**
     * Fechas de las repeticiones (sin la original), en orden. Nunca devuelve más de
     * MAX_REPETICIONES + 1 fechas (una de más sirve para detectar que se superó el límite).
     *
     * @return list<string>
     */
    public function fechas(string $original, string $frecuencia, ?int $cantidad, ?string $hasta): array
    {
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $original);
        $fin = $hasta !== null ? CarbonImmutable::createFromFormat('!Y-m-d', $hasta) : null;

        if ($inicio === null || ($fin === null && ($cantidad === null || $cantidad < 1))) {
            return [];
        }

        $fechas = [];

        for ($k = 1; $k <= self::MAX_REPETICIONES + 1; $k++) {
            if ($fin === null && $k > $cantidad) {
                break;
            }

            $fecha = $this->sumar($inicio, $frecuencia, $k);

            if ($fin !== null && $fecha->greaterThan($fin)) {
                break;
            }

            $fechas[] = $fecha->toDateString();
        }

        return $fechas;
    }

    /**
     * Expande los bloques capturados con sus repeticiones. Primero van los bloques capturados
     * (en el mismo orden) y después sus repeticiones; cada repetición reutiliza el id del bloque
     * que ya tenía esa misma ocurrencia, para no duplicar filas al editar.
     *
     * @param  list<DatosDetalle>  $bloques
     * @param  Repeticion|null  $repeticion
     * @param  array<int, array<int, int>>  $existentes  [id del bloque original => [ocurrencia => id]]
     * @return list<BloqueExpandido>
     */
    public function expandir(array $bloques, ?array $repeticion, array $existentes = []): array
    {
        $expandidos = [];

        foreach ($bloques as $bloque) {
            $expandidos[] = ['datos' => $bloque, 'origen' => null, 'ocurrencia' => $repeticion === null ? null : 0];
        }

        if ($repeticion === null) {
            return $expandidos;
        }

        foreach ($bloques as $j => $bloque) {
            $fechas = $this->fechas((string) $bloque['fecha'], $repeticion['frecuencia'], $repeticion['cantidad'], $repeticion['hasta']);
            $idsPrevios = isset($bloque['id']) ? ($existentes[$bloque['id']] ?? []) : [];

            foreach (array_slice($fechas, 0, self::MAX_REPETICIONES) as $k => $fecha) {
                $ocurrencia = $k + 1;
                $datos = [...$bloque, 'id' => $idsPrevios[$ocurrencia] ?? null, 'fecha' => $fecha];
                $expandidos[] = ['datos' => $datos, 'origen' => $j, 'ocurrencia' => $ocurrencia];
            }
        }

        return $expandidos;
    }

    /**
     * Repeticiones ya guardadas: [id del bloque original => [ocurrencia => id]].
     *
     * @return array<int, array<int, int>>
     */
    public function existentes(Reserva $reserva): array
    {
        $mapa = [];

        foreach (ReservaDetalle::query()->where('reserva_id', $reserva->id)->whereNotNull('detalle_origen_id')->get(['id', 'detalle_origen_id', 'ocurrencia']) as $detalle) {
            $mapa[(int) $detalle->detalle_origen_id][(int) $detalle->ocurrencia] = $detalle->id;
        }

        return $mapa;
    }

    private function sumar(CarbonImmutable $inicio, string $frecuencia, int $k): CarbonImmutable
    {
        return match ($frecuencia) {
            'diaria' => $inicio->addDays($k),
            'semanal' => $inicio->addWeeks($k),
            'mensual' => $inicio->addMonthsNoOverflow($k),
            'anual' => $inicio->addYearsNoOverflow($k),
            default => throw new \InvalidArgumentException("Frecuencia desconocida: {$frecuencia}"),
        };
    }
}
