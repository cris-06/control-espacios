<?php

namespace App\Services;

use App\Models\EstadoReserva;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\ReservaLicenciatura;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de reservas con sus detalles. No cambia el estado:
 * eso lo hace exclusivamente ReservaWorkflowService.
 *
 * @phpstan-type DatosRequerimiento array{requerimiento_id: int, cantidad: int}
 * @phpstan-type DatosDetalle array{id?: int|null, espacio_id: int, fecha: string, hora_inicio: string, hora_fin: string, cantidad_alumnos?: int|null, lugar_especifico?: string|null, descripcion_actividad?: string|null, material_requerido?: string|null, observaciones?: string|null, docente_responsable_id?: int|null, requerimientos?: list<DatosRequerimiento>|null}
 *
 * @phpstan-import-type BloqueExpandido from RecurrenciaReserva
 */
class ReservaService
{
    public function __construct(
        private readonly ReservaWorkflowService $workflow,
        private readonly AnticipacionReserva $anticipacion,
        private readonly RecurrenciaReserva $recurrencia,
    ) {}

    /**
     * Crea la reserva en borrador, asigna coordinador y registra 'creada' en el historial.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<DatosDetalle>  $detalles
     */
    public function crear(User $creador, array $datos, array $detalles): Reserva
    {
        return DB::transaction(function () use ($creador, $datos, $detalles): Reserva {
            $coordinador = $this->extraerCoordinador($datos);
            $adicionales = $this->extraerAdicionales($datos);
            $reserva = new Reserva($this->limpiar($datos));
            $reserva->usuario_creador_id = $creador->id;
            $reserva->setRelation('creador', $creador);
            // Coordinador responsable elegido (validado contra la licenciatura); asignarCoordinador
            // lo conserva si es válido o pone el creador si él coordina la licenciatura.
            $reserva->coordinador_id = $coordinador;
            $this->workflow->asignarCoordinador($reserva);
            $reserva->save();

            $reserva->folio = Reserva::folioPara($reserva->id);
            $reserva->folio_institucional = $this->folioInstitucional($reserva);
            $reserva->save();

            // Bloques capturados más las fechas de la repetición (si la hay).
            $this->sincronizarDetalles($reserva, $this->recurrencia->expandir($detalles, $this->repeticionDe($reserva)));
            $this->sincronizarLicenciaturas($reserva, $adicionales);

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
     * Concurrencia: se bloquea la fila de la reserva (el mismo bloqueo, y en el mismo orden,
     * que toma ReservaWorkflowService::ejecutar) y el permiso se vuelve a comprobar sobre esa
     * copia bloqueada. Así una edición no puede cruzarse con un envío, aprobación o
     * autorización: espera a que termine y, si el estado ya no admite editar, se rechaza sin
     * escribir nada. La instancia recibida solo se actualiza al final, con lo guardado.
     *
     * Directivo (admin / superadmin, solo en pendiente_admin según ReservaPolicy::update): la
     * excepción de los 10 días se decide AQUÍ, con el usuario autenticado y el permiso
     * comprobado sobre la copia bloqueada; ningún dato del navegador puede activarla. A cambio,
     * después de sincronizar se aplican las comprobaciones de la autorización
     * (ReservaWorkflowService::validarEdicionDirectivo) y se registra 'editada_admin'. El estado
     * no cambia: sigue pendiente_admin hasta que el directivo decida.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<DatosDetalle>  $detalles
     * @param  string|null  $motivo  motivo de la edición del directivo (se guarda en el historial)
     *
     * @throws AuthorizationException si, con el estado actual, el editor ya no puede editarla
     */
    public function actualizar(Reserva $reserva, User $editor, array $datos, array $detalles, ?string $motivo = null): Reserva
    {
        return DB::transaction(function () use ($reserva, $editor, $datos, $detalles, $motivo): Reserva {
            $actual = Reserva::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();

            // No basta la comprobación del controlador: se hizo antes del bloqueo, con un estado
            // que pudo cambiar mientras tanto.
            Gate::forUser($editor)->authorize('update', $actual);

            // Edición del directivo: el permiso ya se comprobó bajo bloqueo, y para administración
            // solo existe en pendiente_admin.
            $esDirectivo = $editor->esAdministrador() && $actual->enEstado(EstadoReserva::PENDIENTE_ADMIN);

            // El directivo no puede cambiar la licenciatura (ni, por tanto, el coordinador asignado):
            // podía dejar la solicitud con un coordinador elegido al azar o sin coordinador, o impedir
            // que la docente la corrija y reenvíe. ReservaRequest ya lo rechaza; aquí se comprueba
            // de nuevo bajo bloqueo, antes de escribir nada.
            if ($esDirectivo && array_key_exists('licenciatura_id', $datos) && (int) $datos['licenciatura_id'] !== $actual->licenciatura_id) {
                throw ValidationException::withMessages([
                    'licenciatura_id' => 'El directivo no puede cambiar la licenciatura de la solicitud.',
                ]);
            }

            // Bloques capturados más las fechas de la repetición: la configuración enviada o, si el
            // formulario no la envió, la guardada. Cada repetición reutiliza su fila (sin duplicar).
            $repeticion = array_key_exists('repeticion_frecuencia', $datos)
                ? $this->repeticionDeDatos($datos)
                : $this->repeticionDe($actual);
            $expandidos = $this->recurrencia->expandir($detalles, $repeticion, $this->recurrencia->existentes($actual));

            // Ya enviada: un bloque nuevo o con otro espacio, fecha u horario (también una fecha
            // generada) debe cumplir la anticipación mínima (el directivo, solo que no sea una fecha
            // pasada). Se valida antes de escribir nada; si falla, no se guarda nada.
            try {
                $this->anticipacion->validarCambios($actual, array_column($expandidos, 'datos'), excepcionDirectivo: $esDirectivo);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages($this->erroresPorBloque($e->errors(), $expandidos));
            }

            $coordinador = $this->extraerCoordinador($datos);
            $adicionales = $this->extraerAdicionales($datos);

            // Mismo criterio para el coordinador responsable: en pendiente_admin la coordinación ya
            // revisó la solicitud, así que el directivo no lo cambia.
            if ($esDirectivo && $coordinador !== null && $coordinador !== $actual->coordinador_id) {
                throw ValidationException::withMessages([
                    'coordinador_id' => 'El directivo no puede cambiar el coordinador responsable de la solicitud.',
                ]);
            }

            $licenciaturaAnterior = $actual->licenciatura_id;

            $actual->fill($this->limpiar($datos));

            if ($coordinador !== null) {
                $actual->coordinador_id = $coordinador;
            }

            if ($actual->licenciatura_id !== $licenciaturaAnterior || $actual->isDirty('coordinador_id')) {
                $this->workflow->asignarCoordinador($actual);
            }

            $camposCambiados = array_keys($actual->getDirty());

            $actual->save();
            $detallesCambiaron = $this->sincronizarDetalles($actual, $expandidos);

            if ($this->sincronizarLicenciaturas($actual, $adicionales)) {
                $camposCambiados[] = 'licenciaturas_adicionales';
            }

            if ($esDirectivo) {
                // Mismas comprobaciones que la autorización, con la fila ya bloqueada (orden:
                // reserva y después espacios). Si fallan, se revierte toda la transacción.
                $this->workflow->validarEdicionDirectivo($actual, $editor);

                if ($camposCambiados !== [] || $detallesCambiaron) {
                    $motivo = trim((string) $motivo);
                    $actual->historial()->create([
                        'usuario_id' => $editor->id,
                        'accion' => HistorialReserva::EDITADA_ADMIN,
                        'comentario' => $this->resumenCambios($camposCambiados, $detallesCambiaron).($motivo !== '' ? ' Motivo: '.$motivo : ''),
                    ]);
                }
            }

            // El superadmin que edita en la etapa de coordinación cuenta como coordinación.
            $esCoordinadorAjeno = ! $esDirectivo
                && $actual->usuario_creador_id !== $editor->id
                && ($editor->coordinaLicenciatura($licenciaturaAnterior) || $editor->esSuperadmin());

            if ($esCoordinadorAjeno && ($camposCambiados !== [] || $detallesCambiaron)) {
                $actual->historial()->create([
                    'usuario_id' => $editor->id,
                    'accion' => HistorialReserva::EDITADA_COORDINADOR,
                    'comentario' => $this->resumenCambios($camposCambiados, $detallesCambiaron),
                ]);
            }

            $reserva->setRawAttributes($actual->getAttributes(), true);
            $reserva->unsetRelations();

            return $reserva;
        });
    }

    /**
     * Folio institucional (Formato 10), una sola vez al crear y dentro de su transacción:
     * siglas oficiales de la licenciatura + fecha de creación GUARDADA + id de la reserva.
     * El id es único (autoincremento), así que no hace falta contador ni MAX(id)+1; el índice
     * UNIQUE de la columna queda como protección adicional. Sin siglas válidas: null (solo RES).
     * Nunca se recalcula: actualizar() y el flujo no lo tocan, y no es asignable en masa.
     */
    private function folioInstitucional(Reserva $reserva): ?string
    {
        $siglas = Licenciatura::query()->whereKey($reserva->licenciatura_id)->value('siglas');

        if (! is_string($siglas) || preg_match(Licenciatura::PATRON_SIGLAS, $siglas) !== 1) {
            return null;
        }

        $guardada = Reserva::query()->whereKey($reserva->id)->firstOrFail(['id', 'fecha_creacion'])->fecha_creacion;

        return $guardada === null ? null : Reserva::folioInstitucionalPara($siglas, $guardada, $reserva->id);
    }

    /**
     * @param  list<string>  $campos
     */
    private function resumenCambios(array $campos, bool $detallesCambiaron): string
    {
        $etiquetas = [
            'licenciatura_id' => 'licenciatura', 'actividad_id' => 'tipo de actividad',
            'nombre_actividad' => 'nombre de la actividad', 'objetivo' => 'objetivo',
            'tipo_formato' => 'tipo de formato', 'cuatrimestres' => 'cuatrimestres',
            'asignaturas' => 'asignaturas', 'costo_alumno' => 'costo por alumno',
            'promocion_redes' => 'promoción en redes',
            'docente_responsable_id' => 'docente responsable',
            'coordinador_id' => 'coordinador responsable', 'cantidad_total_alumnos' => 'cantidad total de alumnos',
            'descripcion_actividad' => 'descripción de la actividad', 'observaciones' => 'observaciones',
            'licenciaturas_adicionales' => 'licenciaturas participantes e invitadas',
            'repeticion_frecuencia' => 'repetición', 'repeticion_cantidad' => 'repetición', 'repeticion_hasta' => 'repetición',
        ];

        $partes = array_values(array_unique(array_filter(array_map(fn (string $campo) => $etiquetas[$campo] ?? null, $campos))));

        if ($detallesCambiaron) {
            $partes[] = 'espacios y horarios';
        }

        return 'Cambió: '.implode(', ', $partes).'.';
    }

    /**
     * Actualiza los detalles con id, crea los nuevos y elimina los que ya no vienen (también las
     * repeticiones que sobran al cambiar o quitar la repetición). Las repeticiones quedan
     * enlazadas a su bloque original (detalle_origen_id, ocurrencia).
     *
     * @param  list<BloqueExpandido>  $expandidos  resultado de RecurrenciaReserva::expandir
     * @return bool si hubo algún cambio en los detalles
     */
    private function sincronizarDetalles(Reserva $reserva, array $expandidos): bool
    {
        $conservados = [];
        $cambio = false;
        // id guardado y requerimientos de cada bloque capturado (por su posición), para enlazar
        // y copiar a sus repeticiones.
        $originales = [];

        // expandir() pone primero los bloques capturados: ya existen al guardar sus repeticiones.
        foreach ($expandidos as $bloque) {
            $datos = $bloque['datos'];
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

            $origen = $bloque['origen'];

            if ($origen !== null) {
                // Repetición: los mismos requerimientos que su bloque original.
                $requerimientos = $originales[$origen]['requerimientos'];
            }

            $detalle->fill($datos);
            $detalle->reserva_id = $reserva->id;
            $detalle->detalle_origen_id = $origen === null ? null : $originales[$origen]['id'];
            $detalle->ocurrencia = $bloque['ocurrencia'];
            $cambio = $cambio || ! $detalle->exists || $detalle->isDirty();
            $detalle->save();

            if ($requerimientos !== null) {
                $cambio = $this->sincronizarRequerimientos($detalle, $requerimientos) || $cambio;
            }

            if ($origen === null) {
                $originales[] = [
                    'id' => $detalle->id,
                    'requerimientos' => $requerimientos ?? array_values($detalle->requerimientosPedidos()->get(['requerimiento_id', 'cantidad'])
                        ->map(fn ($p): array => ['requerimiento_id' => (int) $p->requerimiento_id, 'cantidad' => (int) $p->cantidad])
                        ->all()),
                ];
            }

            $conservados[] = $detalle->id;
        }

        // Primero las repeticiones y después los bloques originales (la llave detalle_origen_id
        // apunta a ellos).
        $eliminados = ReservaDetalle::query()
            ->where('reserva_id', $reserva->id)
            ->whereNotIn('id', $conservados)
            ->whereNotNull('detalle_origen_id')
            ->delete();
        $eliminados += ReservaDetalle::query()
            ->where('reserva_id', $reserva->id)
            ->whereNotIn('id', $conservados)
            ->delete();

        return $cambio || $eliminados > 0;
    }

    /**
     * Guarda las licenciaturas participantes e invitadas. null = el formulario no las envió (se
     * conservan). Solo escribe si cambió algo.
     *
     * @param  list<array{licenciatura_id: int, tipo: string, cuatrimestres: list<array{id: int, grupos: list<string>}>|null}>|null  $lista
     * @return bool si hubo cambios
     */
    private function sincronizarLicenciaturas(Reserva $reserva, ?array $lista): bool
    {
        if ($lista === null) {
            return false;
        }

        $nuevas = array_map(fn (array $l, int $orden): array => [
            'licenciatura_id' => $l['licenciatura_id'],
            'tipo' => $l['tipo'],
            'cuatrimestres' => $this->ordenarCuatrimestres($l['cuatrimestres']),
            'orden' => $orden,
        ], $lista, array_keys($lista));

        $actuales = $reserva->licenciaturasAdicionales()->get()
            ->map(fn (ReservaLicenciatura $l): array => [
                'licenciatura_id' => $l->licenciatura_id,
                'tipo' => $l->tipo,
                'cuatrimestres' => $l->cuatrimestres,
                'orden' => $l->orden,
            ])->values()->all();

        if ($nuevas === $actuales) {
            return false;
        }

        $reserva->licenciaturasAdicionales()->delete();
        $reserva->licenciaturasAdicionales()->createMany($nuevas);

        return true;
    }

    /**
     * Configuración de repetición guardada en la reserva, o null si no se repite.
     *
     * @return array{frecuencia: string, cantidad: int|null, hasta: string|null}|null
     */
    private function repeticionDe(Reserva $reserva): ?array
    {
        return $reserva->repeticion_frecuencia === null ? null : [
            'frecuencia' => $reserva->repeticion_frecuencia,
            'cantidad' => $reserva->repeticion_cantidad,
            'hasta' => $reserva->repeticion_hasta?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{frecuencia: string, cantidad: int|null, hasta: string|null}|null
     */
    private function repeticionDeDatos(array $datos): ?array
    {
        $frecuencia = $datos['repeticion_frecuencia'] ?? null;

        return ! is_string($frecuencia) ? null : [
            'frecuencia' => $frecuencia,
            'cantidad' => is_numeric($datos['repeticion_cantidad'] ?? null) ? (int) $datos['repeticion_cantidad'] : null,
            'hasta' => is_string($datos['repeticion_hasta'] ?? null) ? $datos['repeticion_hasta'] : null,
        ];
    }

    /**
     * Los errores de una fecha generada (detalles.N con N fuera de los bloques capturados) se
     * pasan al bloque original del formulario, indicando la fecha de la repetición.
     *
     * @param  array<string, list<string>>  $errores
     * @param  list<BloqueExpandido>  $expandidos
     * @return array<string, list<string>>
     */
    private function erroresPorBloque(array $errores, array $expandidos): array
    {
        $resultado = [];

        foreach ($errores as $clave => $mensajes) {
            if (preg_match('/^detalles\.(\d+)\.(.+)$/', $clave, $m) && isset($expandidos[(int) $m[1]]) && $expandidos[(int) $m[1]]['origen'] !== null) {
                $bloque = $expandidos[(int) $m[1]];
                $fecha = Date::parse((string) $bloque['datos']['fecha'])->format('d/m/Y');
                $clave = "detalles.{$bloque['origen']}.{$m[2]}";
                $mensajes = array_map(fn (string $mensaje): string => "Repetición del {$fecha}: ".mb_lcfirst($mensaje), $mensajes);
            }

            $resultado[$clave] = [...($resultado[$clave] ?? []), ...$mensajes];
        }

        return $resultado;
    }

    /**
     * Saca de los datos las licenciaturas adicionales (no son columnas de reservas).
     *
     * @param  array<string, mixed>  $datos
     * @return list<array{licenciatura_id: int, tipo: string, cuatrimestres: list<array{id: int, grupos: list<string>}>|null}>|null
     */
    private function extraerAdicionales(array &$datos): ?array
    {
        $lista = $datos['licenciaturas_adicionales'] ?? null;
        unset($datos['licenciaturas_adicionales']);

        if (! is_array($lista)) {
            return null;
        }

        return array_values(array_map(fn (mixed $l): array => [
            'licenciatura_id' => (int) (is_array($l) ? ($l['licenciatura_id'] ?? 0) : 0),
            'tipo' => (string) (is_array($l) ? ($l['tipo'] ?? '') : ''),
            'cuatrimestres' => is_array($l) && is_array($l['cuatrimestres'] ?? null)
                ? array_values(array_map(fn (mixed $c): array => [
                    'id' => (int) (is_array($c) ? ($c['id'] ?? 0) : 0),
                    'grupos' => array_values(array_map('strval', is_array($c) ? (array) ($c['grupos'] ?? []) : [])),
                ], $l['cuatrimestres']))
                : null,
        ], $lista));
    }

    /**
     * Cuatrimestres ordenados con sus grupos en orden alfabético (lista vacía = null), como en
     * reservas.cuatrimestres.
     *
     * @param  list<array{id: int, grupos: list<string>}>|null  $cuatrimestres
     * @return list<array{id: int, grupos: list<string>}>|null
     */
    private function ordenarCuatrimestres(?array $cuatrimestres): ?array
    {
        $lista = array_map(function (array $c): array {
            $grupos = array_values(array_unique(array_map('strval', $c['grupos'])));
            sort($grupos);

            return ['id' => (int) $c['id'], 'grupos' => $grupos];
        }, $cuatrimestres ?? []);
        usort($lista, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $lista === [] ? null : $lista;
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
     * Saca de los datos el coordinador responsable elegido (no es asignable en masa).
     *
     * @param  array<string, mixed>  $datos
     */
    private function extraerCoordinador(array &$datos): ?int
    {
        $id = $datos['coordinador_id'] ?? null;
        unset($datos['coordinador_id']);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * La base exige objetivo NOT NULL: un borrador sin objetivo se guarda vacío. Los
     * cuatrimestres se guardan ordenados (y sus grupos en orden alfabético) y las listas vacías
     * como NULL, para que reenviar lo mismo no cuente como cambio en el historial.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function limpiar(array $datos): array
    {
        $datos['objetivo'] = trim((string) ($datos['objetivo'] ?? ''));

        if (array_key_exists('cuatrimestres', $datos)) {
            $cuatrimestres = array_map(function (mixed $c): array {
                $grupos = array_values(array_unique(array_map('strval', (array) ($c['grupos'] ?? []))));
                sort($grupos);

                return ['id' => (int) $c['id'], 'grupos' => $grupos];
            }, array_values((array) $datos['cuatrimestres']));
            usort($cuatrimestres, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $datos['cuatrimestres'] = $cuatrimestres === [] ? null : $cuatrimestres;
        }

        if (array_key_exists('asignaturas', $datos)) {
            $asignaturas = array_values(array_map('strval', (array) $datos['asignaturas']));
            $datos['asignaturas'] = $asignaturas === [] ? null : $asignaturas;
        }

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
