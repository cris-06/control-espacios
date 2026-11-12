<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\ReservaService;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * Regresión de la carrera entre editar y una transición (enviar, aprobar, autorizar).
 *
 * El controlador autoriza con la reserva que cargó al recibir la petición; si mientras tanto
 * otra petición cambia su estado, ReservaService::actualizar debe trabajar con la fila bloqueada
 * (lockForUpdate) y volver a comprobar el permiso sobre ella. Aquí se simula esa intercalación
 * pasando al servicio una instancia leída ANTES de la transición.
 *
 * Límite: SQLite ignora FOR UPDATE, así que estas pruebas verifican que se usa el estado vigente
 * y que el permiso se revisa de nuevo, no la espera real entre dos conexiones. Eso solo se puede
 * comprobar en MySQL con dos procesos (pendiente, sin autorización todavía).
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-10 09:30:00'));

    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->admin = User::factory()->rol('admin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['nombre' => 'Auditorio 1', 'capacidad' => 40]);
    $this->workflow = app(W::class);
    $this->servicio = app(ReservaService::class);

    // Borrador para el 25/11 (15 días: cumple la anticipación al enviarse hoy).
    $this->borrador = fn (?User $creador = null): Reserva => Reserva::factory()
        ->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => '2026-11-25'])
        ->create(['usuario_creador_id' => ($creador ?? $this->docente)->id, 'licenciatura_id' => $this->licenciatura->id, 'actividad_id' => $this->actividad->id]);

    $this->datos = fn (Reserva $reserva, array $bloque = [], array $extra = []): array => [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Cambiado en otra pestaña',
        'objetivo' => 'Objetivo cambiado',
        ...$extra,
    ];

    $this->bloques = function (Reserva $reserva, array $cambios = []): array {
        $d = ReservaDetalle::query()->where('reserva_id', $reserva->id)->firstOrFail();

        return [[
            'id' => $d->id,
            'espacio_id' => $d->espacio_id,
            'fecha' => $d->fecha->toDateString(),
            'hora_inicio' => ReservaDetalle::hhmm($d->hora_inicio),
            'hora_fin' => ReservaDetalle::hhmm($d->hora_fin),
            'cantidad_alumnos' => $d->cantidad_alumnos,
            ...$cambios,
        ]];
    };

    // Lo que una edición rechazada no debe tocar.
    $this->huella = fn (Reserva $reserva): array => [
        'reserva' => Arr::only(Reserva::query()->findOrFail($reserva->id)->toArray(), ['estado_id', 'nombre_actividad', 'objetivo', 'fecha_entrega_formato', 'usuario_coordinador_id']),
        'detalles' => ReservaDetalle::query()->where('reserva_id', $reserva->id)->orderBy('id')->get()
            ->map(fn (ReservaDetalle $d): array => Arr::only($d->toArray(), ['id', 'espacio_id', 'fecha', 'hora_inicio', 'hora_fin', 'cantidad_alumnos', 'estado_detalle']))->all(),
        'historial' => HistorialReserva::query()->where('reserva_id', $reserva->id)->orderBy('id')->pluck('accion')->all(),
        'notificaciones' => Notificacion::query()->count(),
    ];
});

test('a draft sent while an edit is pending cannot be edited with the stale draft', function () {
    $reserva = ($this->borrador)();
    $leida = Reserva::query()->findOrFail($reserva->id); // la petición de edición ya autorizó con esta copia

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $antes = ($this->huella)($reserva);

    // Con la copia vieja (borrador) intenta mover la fecha a 2 días: ya no puede editar.
    expect(fn () => $this->servicio->actualizar($leida, $this->docente, ($this->datos)($reserva), ($this->bloques)($reserva, ['fecha' => '2026-11-12'])))
        ->toThrow(AuthorizationException::class);

    expect(($this->huella)($reserva))->toBe($antes)
        ->and($antes['reserva']['estado_id'])->toBe(E::PENDIENTE_COORDINADOR);
});

test('a request approved by coordination while the coordinator edits it is not modified', function () {
    $reserva = ($this->borrador)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $leida = Reserva::query()->findOrFail($reserva->id); // pendiente_coordinador: editable por coordinación

    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    $antes = ($this->huella)($reserva);

    expect(fn () => $this->servicio->actualizar($leida, $this->coordinador, ($this->datos)($reserva), ($this->bloques)($reserva, ['hora_fin' => '12:30'])))
        ->toThrow(AuthorizationException::class);

    // Sin cambios parciales: ni datos, ni bloques, ni «editada_coordinador», ni notificaciones.
    expect(($this->huella)($reserva))->toBe($antes)
        ->and($antes['reserva']['estado_id'])->toBe(E::PENDIENTE_ADMIN)
        ->and($antes['historial'])->not->toContain(HistorialReserva::EDITADA_COORDINADOR);
});

test('a request authorized while it was being edited stays as authorized', function () {
    $reserva = ($this->borrador)($this->coordinador);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->admin, W::SOLICITAR_CORRECCION, 'Revisa el horario');
    $leida = Reserva::query()->findOrFail($reserva->id); // en corrección: editable por su creador

    $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Autorizada');
    $antes = ($this->huella)($reserva);

    expect(fn () => $this->servicio->actualizar($leida, $this->coordinador, ($this->datos)($reserva), ($this->bloques)($reserva)))
        ->toThrow(AuthorizationException::class);

    expect(($this->huella)($reserva))->toBe($antes)
        ->and($antes['reserva']['estado_id'])->toBe(E::AUTORIZADA);
});

test('the anticipation check uses the current state, not the stale copy', function () {
    $reserva = ($this->borrador)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Precisa el objetivo');

    // Copia vieja que todavía dice «borrador, sin entregar» (sin anticipación al guardar),
    // aunque en la base la reserva ya se envió y está en corrección (el creador sí puede editar).
    $leida = Reserva::query()->findOrFail($reserva->id);
    $leida->setRawAttributes([...$leida->getAttributes(), 'estado_id' => E::BORRADOR, 'fecha_entrega_formato' => null], true);
    $antes = ($this->huella)($reserva);

    expect(fn () => $this->servicio->actualizar($leida, $this->docente, ($this->datos)($reserva), ($this->bloques)($reserva, ['fecha' => '2026-11-12'])))
        ->toThrow(ValidationException::class);

    expect(($this->huella)($reserva))->toBe($antes);
});

test('a teacher whose resend happened in another tab loses the permission to edit', function () {
    $reserva = ($this->borrador)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Precisa el objetivo');
    $leida = Reserva::query()->findOrFail($reserva->id);

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $antes = ($this->huella)($reserva);

    // Cambio que no toca fechas: el rechazo es por permiso, no por anticipación.
    expect(fn () => $this->servicio->actualizar($leida, $this->docente, ($this->datos)($reserva), ($this->bloques)($reserva, ['cantidad_alumnos' => 30])))
        ->toThrow(AuthorizationException::class);

    expect(($this->huella)($reserva))->toBe($antes);
});

test('over HTTP an allowed edit still works and returns the saved values', function () {
    $reserva = ($this->borrador)();

    $this->actingAs($this->docente)
        ->put(route('reservas.update', $reserva), [...($this->datos)($reserva), 'detalles' => ($this->bloques)($reserva, ['hora_fin' => '11:30'])])
        ->assertRedirect(route('reservas.show', $reserva))
        ->assertSessionHasNoErrors();

    $leida = Reserva::query()->findOrFail($reserva->id);
    $devuelta = $this->servicio->actualizar($leida, $this->docente, ($this->datos)($reserva, extra: ['objetivo' => 'Otro objetivo']), ($this->bloques)($reserva));

    expect($devuelta)->toBe($leida)
        ->and($devuelta->objetivo)->toBe('Otro objetivo')
        ->and($devuelta->isDirty())->toBeFalse()
        ->and(ReservaDetalle::query()->where('reserva_id', $reserva->id)->value('hora_fin'))->toBe('11:30:00')
        ->and(Reserva::query()->findOrFail($reserva->id)->estado_id)->toBe(E::BORRADOR);
});
