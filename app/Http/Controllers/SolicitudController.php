<?php

namespace App\Http\Controllers;

use App\Models\Espacio;
use App\Models\EstadoReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\ReservaWorkflowService;
use App\Support\FilasReserva;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Listado de reservas. Cada rol solo ve lo que le corresponde
 * (Reserva::scopeVisiblesPara, mismo criterio que ReservaPolicy::view).
 * Filtrar por una licenciatura ajena responde 403.
 */
class SolicitudController extends Controller
{
    /** Acciones del listado para administración (el superadmin agrega la de coordinación). */
    private const DE_ADMINISTRACION = [
        ReservaWorkflowService::AUTORIZAR,
        ReservaWorkflowService::DENEGAR,
        ReservaWorkflowService::SOLICITAR_CORRECCION,
        ReservaWorkflowService::CANCELAR,
        ReservaWorkflowService::ENVIAR_EDICION,
    ];

    public function __construct(
        private readonly ReservaWorkflowService $workflow,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        // Las licenciaturas del usuario se usan en cada comprobación de permisos por fila.
        $user->loadMissing(['rol', 'licenciaturas']);
        $estadoId = $request->integer('estado_id') ?: null;
        $licenciaturaId = $request->integer('licenciatura_id') ?: null;

        abort_if(
            $licenciaturaId !== null && ! $user->puedeConsultarLicenciatura($licenciaturaId),
            403,
            'No tienes acceso a las reservas de esa licenciatura.',
        );

        $reservas = Reserva::query()
            ->visiblesPara($user)
            ->when($estadoId, fn ($query, int $id) => $query->where('estado_id', $id))
            ->when($licenciaturaId, fn ($query, int $id) => $query->where('licenciatura_id', $id))
            ->with([...FilasReserva::RELACIONES_RESERVA, 'detalles.espacio:id,nombre'])
            ->orderByDesc('fecha_actualizacion')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('solicitudes/Index', [
            'filtros' => ['estado_id' => $estadoId, 'licenciatura_id' => $licenciaturaId],
            'opcionesEstados' => EstadoReserva::opciones(),
            'opcionesLicenciaturas' => $user->licenciaturasConsultables()
                ->map(fn (Licenciatura $l): array => ['id' => $l->id, 'nombre' => $l->nombre])
                ->values(),
            'solicitudes' => $reservas->map(fn (Reserva $reserva): array => [
                ...FilasReserva::deReserva($reserva),
                'acciones' => $this->acciones($reserva, $user),
            ]),
            'puedeCrear' => $user->can('create', Reserva::class),
            // Espacios para el diálogo «Reprogramar» (solo administración).
            'opcionesEspacios' => $user->esAdministrador()
                ? Espacio::query()->where('estatus', 'disponible')->orderBy('nombre')->get(['id', 'nombre'])
                : [],
        ]);
    }

    /**
     * Acciones de la columna ACCIONES del listado, calculadas con la política y el flujo
     * existentes (ReservaPolicy y ReservaWorkflowService::disponibles), así que solo aparecen
     * las que el usuario puede ejecutar ahora mismo. Ocultarlas aquí es solo presentación:
     * cada ruta vuelve a autorizar en el servidor.
     *
     * Por rol, en el listado:
     * - docente: ver y editar (el envío sigue en el formulario y el detalle);
     * - coordinación: ver, editar y «enviar a directivo» (= aprobar); devolver a corrección
     *   queda en el detalle;
     * - administración: ver, autorizar, rechazar (= denegar) y mandar a corregir (= solicitar
     *   corrección); la edición no aparece en el listado.
     *
     * - administración, en una autorizada que no ha pasado: cancelar, reprogramar y enviar a
     *   edición (ReservaPolicy::cancelar / reprogramar / enviarEdicion).
     *
     * @return array{ver: bool, editar: bool, transiciones: list<array{accion: string, etiqueta: string, requiereObservacion: bool}>, reprogramar: list<array{id: int, espacio_id: int, espacio: string, fecha: string, hora_inicio: string, hora_fin: string}>|null}
     */
    private function acciones(Reserva $reserva, User $user): array
    {
        $esAdmin = $user->esAdministrador();
        $permitidas = match (true) {
            // El superadmin también hace la revisión de coordinación (ver ReservaPolicy::revisar).
            $user->esSuperadmin() => [ReservaWorkflowService::APROBAR, ...self::DE_ADMINISTRACION],
            $esAdmin => self::DE_ADMINISTRACION,
            $user->esCoordinador() => [ReservaWorkflowService::APROBAR],
            default => [],
        };
        $etiquetas = [
            ReservaWorkflowService::APROBAR => 'Enviar a directivo',
            ReservaWorkflowService::AUTORIZAR => 'Autorizar',
            ReservaWorkflowService::DENEGAR => 'Rechazar',
            ReservaWorkflowService::SOLICITAR_CORRECCION => 'Mandar a corregir',
            ReservaWorkflowService::CANCELAR => 'Cancelar',
            ReservaWorkflowService::ENVIAR_EDICION => 'Enviar a edición',
        ];

        $transiciones = [];

        if ($permitidas !== []) {
            foreach ($this->workflow->disponibles($reserva, $user) as $accion) {
                if (in_array($accion['accion'], $permitidas, true)) {
                    $transiciones[] = [
                        'accion' => $accion['accion'],
                        'etiqueta' => $etiquetas[$accion['accion']],
                        'requiereObservacion' => $accion['requiereObservacion'],
                    ];
                }
            }
        }

        return [
            'ver' => $user->can('view', $reserva),
            'editar' => ! $esAdmin && $user->can('update', $reserva),
            'transiciones' => $transiciones,
            // Bloques que administración puede reprogramar (null si no puede: ReservaPolicy::reprogramar).
            'reprogramar' => $user->can('reprogramar', $reserva)
                ? array_values($reserva->detalles->map(fn (ReservaDetalle $d): array => [
                    'id' => $d->id,
                    'espacio_id' => $d->espacio_id,
                    'espacio' => $d->espacio->nombre,
                    'fecha' => $d->fecha->toDateString(),
                    'hora_inicio' => ReservaDetalle::hhmm($d->hora_inicio),
                    'hora_fin' => ReservaDetalle::hhmm($d->hora_fin),
                ])->all())
                : null,
        ];
    }
}
