<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva as H;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\DisponibilidadEspacioService;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Acciones de administración sobre reservas AUTORIZADAS: cancelar, reprogramar y enviar a
 * edición. Solo admin / superadmin, y solo mientras la fecha de la reserva no haya pasado.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lsc = Licenciatura::factory()->create(['nombre' => 'Sistemas']);
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->lsc->id);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->lsc->id);
    $this->admin = User::factory()->rol('admin')->create();
    $this->superadmin = User::factory()->rol('superadmin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40]);
    $this->otroEspacio = Espacio::factory()->create(['capacidad' => 40]);
    $this->workflow = app(W::class);

    // Reserva autorizada por el flujo completo: docente -> coordinación -> administración.
    $this->autorizada = function (string $fecha = '2026-12-15', string $inicio = '10:00', string $fin = '12:00'): Reserva {
        $this->actingAs($this->docente)->post(route('reservas.store'), [
            'licenciatura_id' => $this->lsc->id, 'actividad_id' => $this->actividad->id,
            'nombre_actividad' => 'Jornada', 'objetivo' => 'Objetivo de prueba',
            'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => $fecha, 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'cantidad_alumnos' => 20]],
        ])->assertSessionHasNoErrors();

        $r = Reserva::query()->latest('id')->firstOrFail();
        $this->workflow->ejecutar($r, $this->docente, W::ENVIAR);
        $this->workflow->ejecutar($r->refresh(), $this->coordinador, W::APROBAR);
        $this->workflow->ejecutar($r->refresh(), $this->admin, W::AUTORIZAR, 'Visto bueno');

        return $r->refresh();
    };

    $this->transicion = fn (User $usuario, Reserva $r, string $accion, ?string $observacion = 'Motivo de prueba') => $this
        ->actingAs($usuario)
        ->post(route('reservas.transicion', $r), ['accion' => $accion, 'observacion' => $observacion, 'origen' => 'listado']);

    $this->reprogramar = fn (User $usuario, Reserva $r, array $cambios = []) => $this
        ->actingAs($usuario)
        ->patch(route('reservas.reprogramar', $r), [
            'detalle_id' => $r->detalles()->value('id'),
            'espacio_id' => $this->espacio->id,
            'fecha' => '2026-12-18',
            'hora_inicio' => '14:00',
            'hora_fin' => '16:00',
            'motivo' => 'Cambio de sede del evento',
            ...$cambios,
        ]);

    // Acciones de la fila en el listado de quien consulta.
    $this->acciones = function (User $usuario, Reserva $reserva): ?array {
        $fila = null;
        $this->actingAs($usuario)->get(route('solicitudes.index'))->assertOk()
            ->assertInertia(function (Assert $page) use (&$fila, $reserva) {
                $fila = collect($page->toArray()['props']['solicitudes'])->firstWhere('id', $reserva->id);
            });

        return $fila['acciones'] ?? null;
    };
});

// --- Cancelar ---

test('the admin cancels an authorized reservation and the slot becomes free', function () {
    $r = ($this->autorizada)();

    ($this->transicion)($this->admin, $r, W::CANCELAR, 'El evento se suspendió')
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $r->refresh();
    expect($r->estado_id)->toBe(E::CANCELADA)
        ->and($r->observaciones_admin)->toBe('El evento se suspendió')
        ->and($r->detalles()->pluck('estado_detalle')->unique()->all())->toBe([ReservaDetalle::CANCELADA])
        ->and($r->historial()->where('accion', H::CANCELADA)->value('comentario'))->toBe('El evento se suspendió');

    // Notifica al solicitante y al coordinador de la licenciatura.
    expect(Notificacion::query()->where('reserva_id', $r->id)->where('titulo', 'Reserva cancelada')->pluck('usuario_id')->sort()->values()->all())
        ->toBe(collect([$this->docente->id, $this->coordinador->id])->sort()->values()->all());

    // El espacio y horario quedan libres en disponibilidad.
    expect(app(DisponibilidadEspacioService::class)->choques($this->espacio->id, '2026-12-15', '10:00', '12:00'))->toBeEmpty();
});

test('cancelling requires a reason', function () {
    $r = ($this->autorizada)();

    ($this->transicion)($this->admin, $r, W::CANCELAR, '  ')->assertSessionHasErrors('observacion');

    expect($r->refresh()->estado_id)->toBe(E::AUTORIZADA);
});

test('the superadmin can also cancel', function () {
    $r = ($this->autorizada)();

    ($this->transicion)($this->superadmin, $r, W::CANCELAR)->assertSessionHasNoErrors();

    expect($r->refresh()->estado_id)->toBe(E::CANCELADA);
});

test('a reservation that is not authorized cannot be cancelled', function () {
    $r = ($this->autorizada)();
    ($this->transicion)($this->admin, $r, W::CANCELAR)->assertSessionHasNoErrors();

    // Ya cancelada: no hay transición desde ese estado.
    ($this->transicion)($this->admin, $r->refresh(), W::CANCELAR)->assertSessionHasErrors('transicion');
});

// --- Reprogramar ---

test('the admin reschedules an authorized reservation without overlaps', function () {
    $r = ($this->autorizada)();

    ($this->reprogramar)($this->admin, $r, ['espacio_id' => $this->otroEspacio->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $r->refresh();
    $detalle = $r->detalles()->firstOrFail();

    expect($r->estado_id)->toBe(E::AUTORIZADA)
        ->and($detalle->espacio_id)->toBe($this->otroEspacio->id)
        ->and($detalle->fecha->toDateString())->toBe('2026-12-18')
        ->and(ReservaDetalle::hhmm($detalle->hora_inicio))->toBe('14:00')
        ->and(ReservaDetalle::hhmm($detalle->hora_fin))->toBe('16:00')
        ->and($detalle->estado_detalle)->toBe(ReservaDetalle::AUTORIZADA);

    $comentario = (string) $r->historial()->where('accion', H::EDITADA_ADMIN)->value('comentario');
    expect($comentario)->toContain('15/12/2026 de 10:00 a 12:00')
        ->toContain('18/12/2026 de 14:00 a 16:00')
        ->toContain('Cambio de sede del evento');

    expect(Notificacion::query()->where('reserva_id', $r->id)->where('titulo', 'Reserva reprogramada')->pluck('usuario_id')->all())
        ->toBe([$this->docente->id]);
});

test('rescheduling onto another authorized reservation is rejected and nothing changes', function () {
    $r = ($this->autorizada)();
    ($this->autorizada)('2026-12-18', '13:00', '15:00');

    ($this->reprogramar)($this->admin, $r)->assertSessionHasErrors('reserva.0');

    $detalle = $r->detalles()->firstOrFail();
    expect($detalle->fecha->toDateString())->toBe('2026-12-15')
        ->and(ReservaDetalle::hhmm($detalle->hora_inicio))->toBe('10:00')
        ->and($r->historial()->where('accion', H::EDITADA_ADMIN)->exists())->toBeFalse()
        ->and(Notificacion::query()->where('titulo', 'Reserva reprogramada')->exists())->toBeFalse();
});

test('rescheduling validates the reason, the date and the schedule', function () {
    $r = ($this->autorizada)();

    ($this->reprogramar)($this->admin, $r, ['motivo' => '', 'fecha' => '2026-11-01', 'hora_fin' => '13:00'])
        ->assertSessionHasErrors(['motivo', 'fecha', 'hora_fin']);
});

test('rescheduling into the slot freed by a cancellation is allowed', function () {
    $r = ($this->autorizada)();
    $otra = ($this->autorizada)('2026-12-18', '13:00', '15:00');
    ($this->transicion)($this->admin, $otra, W::CANCELAR)->assertSessionHasNoErrors();

    ($this->reprogramar)($this->admin, $r)->assertSessionHasNoErrors();
});

// --- Enviar a edición ---

test('the admin sends an authorized reservation back for editing and the teacher resubmits it', function () {
    $r = ($this->autorizada)();

    ($this->transicion)($this->admin, $r, W::ENVIAR_EDICION, 'Corrige el número de alumnos')
        ->assertSessionHasNoErrors();

    $r->refresh();
    expect($r->estado_id)->toBe(E::CORRECCION)
        ->and($r->detalles()->pluck('estado_detalle')->unique()->all())->toBe([ReservaDetalle::PENDIENTE])
        ->and($r->historial()->where('accion', H::CORRECCION_SOLICITADA)->latest('id')->value('comentario'))->toBe('Corrige el número de alumnos')
        ->and(Notificacion::query()->where('reserva_id', $r->id)->where('usuario_id', $this->docente->id)->where('titulo', 'Tu reserva requiere corrección')->exists())->toBeTrue();

    // Ya no ocupa el espacio y el solicitante la reenvía con el flujo normal.
    expect(app(DisponibilidadEspacioService::class)->choques($this->espacio->id, '2026-12-15', '10:00', '12:00'))->toBeEmpty();

    $this->workflow->ejecutar($r, $this->docente, W::ENVIAR);
    expect($r->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

// --- Permisos ---

test('teachers and coordinators get 403 on every action', function (string $quien) {
    $r = ($this->autorizada)();
    $usuario = $this->{$quien};

    ($this->transicion)($usuario, $r, W::CANCELAR)->assertForbidden();
    ($this->transicion)($usuario, $r, W::ENVIAR_EDICION)->assertForbidden();
    ($this->reprogramar)($usuario, $r)->assertForbidden();

    expect($r->refresh()->estado_id)->toBe(E::AUTORIZADA)
        ->and($r->detalles()->firstOrFail()->fecha->toDateString())->toBe('2026-12-15');
})->with(['docente', 'coordinador']);

test('the actions are not available once the reservation date has passed', function () {
    $r = ($this->autorizada)();
    $this->travelTo(Date::parse('2026-12-16 09:00:00'));

    ($this->transicion)($this->admin, $r, W::CANCELAR)->assertForbidden();
    ($this->transicion)($this->admin, $r, W::ENVIAR_EDICION)->assertForbidden();
    ($this->reprogramar)($this->admin, $r, ['fecha' => '2026-12-20'])->assertForbidden();

    $a = ($this->acciones)($this->admin, $r);
    expect($a['transiciones'])->toBe([])->and($a['reprogramar'])->toBeNull();
});

// --- Listado ---

test('the list offers cancel, reschedule and send to editing only to administration', function () {
    $r = ($this->autorizada)();

    foreach ([$this->admin, $this->superadmin] as $usuario) {
        $a = ($this->acciones)($usuario, $r);
        expect(collect($a['transiciones'])->pluck('accion')->all())->toBe([W::CANCELAR, W::ENVIAR_EDICION])
            ->and($a['reprogramar'])->toHaveCount(1)
            ->and($a['reprogramar'][0])->toMatchArray(['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00']);
    }

    foreach ([$this->docente, $this->coordinador] as $usuario) {
        $a = ($this->acciones)($usuario, $r);
        expect($a['transiciones'])->toBe([])->and($a['reprogramar'])->toBeNull();
    }
});

test('the status filter includes Cancelada', function () {
    $this->actingAs($this->admin)->get(route('solicitudes.index', ['estado_id' => E::CANCELADA]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filtros.estado_id', E::CANCELADA)
            ->where('opcionesEstados', fn ($opciones) => collect($opciones)->contains(fn ($o) => $o['id'] === E::CANCELADA && $o['nombre'] === 'Cancelada')));
});
