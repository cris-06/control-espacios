<?php

namespace App\Services;

use App\Models\EstadoReserva;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de reservas con sus detalles. No cambia el estado:
 * eso lo hace exclusivamente ReservaWorkflowService.
 *
 * @phpstan-type DatosRequerimiento array{requerimiento_id: int, cantidad: int}
 * @phpstan-type DatosDetalle array{id?: int|null, espacio_id: int, fecha: string, hora_inicio: string, hora_fin: string, cantidad_alumnos?: int|null, lugar_especifico?: string|null, descripcion_actividad?: string|null, material_requerido?: string|null, observaciones?: string|null, docente_responsable_id?: int|null, requerimientos?: list<DatosRequerimiento>|null}
 */
class ReservaService
{
    public function __construct(
        private readonly ReservaWorkflowService $workflow,
        private readonly AnticipacionReserva $anticipacion,
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
            $reserva = new Reserva($this->limpiar($datos));
            $reserva->usuario_creador_id = $creador->id;
            $reserva->setRelation('creador', $creador);
            $this->workflow->asignarCoordinador($reserva);
            $reserva->save();

            $reserva->folio = Reserva::folioPara($reserva->id);
            $reserva->folio_institucional = $this->folioInstitucional($reserva);
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

            // Ya enviada: un bloque nuevo o con otro espacio, fecha u horario debe cumplir la
            // anticipación mínima (el directivo, solo que no sea una fecha pasada). Se valida
            // antes de escribir nada; si falla, no se guarda nada.
            $this->anticipacion->validarCambios($actual, $detalles, excepcionDirectivo: $esDirectivo);

            $licenciaturaAnterior = $actual->licenciatura_id;

            $actual->fill($this->limpiar($datos));
            $camposCambiados = array_keys($actual->getDirty());

            if ($actual->licenciatura_id !== $licenciaturaAnterior) {
                $this->workflow->asignarCoordinador($actual);
            }

            $actual->save();
            $detallesCambiaron = $this->sincronizarDetalles($actual, $detalles);

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

            $esCoordinadorAjeno = ! $esDirectivo
                && $actual->usuario_creador_id !== $editor->id
                && $editor->coordinaLicenciatura($licenciaturaAnterior);

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
