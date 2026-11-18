<?php

use App\Models\Actividad;
use App\Models\Cuatrimestre;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Ajustes tras las pruebas con usuarios: listado de coordinación con reservas creadas por un
 * coordinador, revisión de coordinación por el superadmin, cuatrimestres y asignaturas múltiples,
 * y calendario mensual con los días bloqueados por la anticipación.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lsc = Licenciatura::factory()->create(['nombre' => 'Sistemas']);
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->lsc->id);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->lsc->id);
    $this->otroCoordinador = User::factory()->rol('coordinador')->create();
    $this->otroCoordinador->licenciaturas()->attach($this->lsc->id);
    $this->admin = User::factory()->rol('admin')->create();
    $this->superadmin = User::factory()->rol('superadmin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40]);
    $this->workflow = app(W::class);

    $this->datos = fn (array $extra = []): array => [
        'licenciatura_id' => $this->lsc->id, 'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Jornada', 'objetivo' => 'Objetivo de prueba',
        'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20]],
        ...$extra,
    ];
    $this->crear = function (?User $autor = null, array $extra = []): Reserva {
        $this->actingAs($autor ?? $this->docente)->post(route('reservas.store'), ($this->datos)($extra))
            ->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };
});

// --- Listado de solicitudes (error 500 «Attempt to read property "nombre" on null») ---

test('a coordinator can list requests that another coordinator created', function () {
    $reserva = ($this->crear)($this->otroCoordinador);
    $this->workflow->ejecutar($reserva, $this->otroCoordinador, W::ENVIAR);

    $this->actingAs($this->coordinador)->get(route('solicitudes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('solicitudes/Index')
            ->where('solicitudes.0.id', $reserva->id));
});

// --- Superadmin: puede hacer la revisión de coordinación ---

test('the superadmin can approve a request pending coordination', function () {
    $reserva = ($this->crear)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    $this->actingAs($this->superadmin)->get(route('solicitudes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('solicitudes.0.acciones.transiciones.0.accion', W::APROBAR));

    $this->actingAs($this->superadmin)
        ->post(route('reservas.transicion', $reserva), ['accion' => W::APROBAR])
        ->assertSessionHasNoErrors();

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($reserva->historial()->where('accion', HistorialReserva::APROBADA_COORDINADOR)->value('usuario_id'))->toBe($this->superadmin->id);
});

test('the superadmin can return a request to correction and edit it at the coordination stage', function () {
    $reserva = ($this->crear)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect($this->superadmin->can('update', $reserva->refresh()))->toBeTrue();

    $this->actingAs($this->superadmin)
        ->put(route('reservas.update', $reserva), ($this->datos)(['nombre_actividad' => 'Jornada editada']))
        ->assertSessionHasNoErrors();

    expect($reserva->historial()->where('accion', HistorialReserva::EDITADA_COORDINADOR)->value('usuario_id'))
        ->toBe($this->superadmin->id);

    $this->workflow->ejecutar($reserva->refresh(), $this->superadmin, W::SOLICITAR_CORRECCION, 'Falta el objetivo.');

    expect($reserva->refresh()->estado_id)->toBe(E::CORRECCION);
});

test('a plain admin still cannot do the coordination review', function () {
    $reserva = ($this->crear)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $reserva->refresh();

    expect($this->admin->can('revisar', $reserva))->toBeFalse()
        ->and($this->admin->can('update', $reserva))->toBeFalse();
});

// --- Periodo cuatrimestral (cuatrimestre + grupos) y asignaturas escritas a mano ---

test('a request stores several cuatrimestres with their groups and handwritten subjects', function () {
    $tercero = Cuatrimestre::query()->where('numero', 3)->value('id');
    $primero = Cuatrimestre::query()->where('numero', 1)->value('id');
    $segundo = Cuatrimestre::query()->where('numero', 2)->value('id');

    $reserva = ($this->crear)(null, [
        'cuatrimestres' => [
            ['id' => $tercero, 'grupos' => ['B']],
            ['id' => $primero, 'grupos' => ['b', 'A', 'B']],
            ['id' => $segundo, 'grupos' => []],
        ],
        'asignaturas' => ['  Bases   de datos ', 'Redes', ''],
    ]);

    expect($reserva->cuatrimestres)->toBe([
        ['id' => $primero, 'grupos' => ['A', 'B']],
        ['id' => $segundo, 'grupos' => []],
        ['id' => $tercero, 'grupos' => ['B']],
    ])
        ->and($reserva->asignaturas)->toBe(['Bases de datos', 'Redes'])
        ->and($reserva->periodoCuatrimestral())->toBe(['1A', '1B', 'Segundo cuatrimestre', '3B']);

    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reserva.cuatrimestres.0', ['id' => $primero, 'grupos' => ['A', 'B']])
            ->where('reserva.asignaturas', ['Bases de datos', 'Redes'])
            ->where('opciones.cuatrimestres.0.numero', 1)
            ->missing('reserva.limite_min_usuarios')
            ->missing('reserva.limite_max_usuarios'));

    $this->actingAs($this->docente)->get(route('reservas.show', $reserva))
        ->assertInertia(fn (Assert $page) => $page->where('reserva.cuatrimestre', '1A, 1B, Segundo cuatrimestre, 3B'));
});

test('cuatrimestres and subjects are optional and validated', function () {
    $reserva = ($this->crear)();

    expect($reserva->cuatrimestres)->toBeNull()->and($reserva->asignaturas)->toBeNull();

    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([
        'cuatrimestres' => [['id' => 999999, 'grupos' => []], ['id' => 1, 'grupos' => ['AB']]],
        'asignaturas' => ['Redes', 'redes', str_repeat('a', 151)],
    ]))->assertSessionHasErrors(['cuatrimestres.0.id', 'cuatrimestres.1.grupos.0', 'asignaturas.1', 'asignaturas.2']);
});

// --- Mensajes debajo de cada campo ---

test('too many students is reported under that block field when saving', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([
        'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 41]],
    ]))->assertSessionHasErrors([
        'detalles.0.cantidad_alumnos' => "Son 41 alumnos y {$this->espacio->nombre} admite como máximo 40.",
    ]);

    expect(Reserva::query()->count())->toBe(0);
});

test('a failed save-and-send returns to the form with each error under its field', function () {
    $reserva = ($this->crear)(null, ['nombre_actividad' => '']);

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)([
        'nombre_actividad' => '',
        'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => '2026-11-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20]],
        'enviar' => true,
    ]))
        ->assertRedirect(route('reservas.edit', $reserva))
        ->assertSessionHasErrors(['reserva.0', 'detalles.0.fecha']);

    expect($reserva->refresh()->estado_id)->toBe(E::BORRADOR);
});

test('a coordinator sending to administration gets the strict errors under each field', function () {
    $reserva = ($this->crear)($this->coordinador, ['nombre_actividad' => '']);

    $this->actingAs($this->coordinador)->put(route('reservas.update', $reserva), ($this->datos)([
        'nombre_actividad' => '',
        'enviar' => true,
    ]))
        ->assertRedirect(route('reservas.edit', $reserva))
        ->assertSessionHasErrors(['reserva.0', 'nombre_actividad' => 'Falta el nombre de la actividad.']);
});

// --- Insignia de Solicitudes del superadmin ---

test('the superadmin badge counts requests pending coordination and administration', function () {
    $enCoordinacion = ($this->crear)();
    $this->workflow->ejecutar($enCoordinacion, $this->docente, W::ENVIAR);
    $enAdministracion = ($this->crear)();
    $this->workflow->ejecutar($enAdministracion, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($enAdministracion->refresh(), $this->coordinador, W::APROBAR);
    ($this->crear)();

    $this->actingAs($this->superadmin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('porAtender', 2));
    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('porAtender', 1));
});

test('participant limits are no longer saved from the form', function () {
    $reserva = ($this->crear)(null, ['limite_min_usuarios' => 5, 'limite_max_usuarios' => 50]);

    expect($reserva->limite_min_usuarios)->toBeNull()->and($reserva->limite_max_usuarios)->toBeNull();
});

// --- Calendario mensual de Disponibilidad ---

test('the availability page includes the month calendar with the first bookable day', function () {
    $reserva = ($this->crear)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    $this->actingAs($this->docente)->get(route('disponibilidad.index', ['fecha' => '2026-12-03']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendario.mes', '2026-12')
            ->where('calendario.fecha_minima', '2026-11-22')
            ->where('calendario.dias_anticipacion', 10)
            ->has('calendario.dias.2026-12-15', 1)
            ->where('calendario.dias.2026-12-15.0.hora_inicio', '10:00'));
});

test('the calendar hides the details of reservations the user cannot see', function () {
    $reserva = ($this->crear)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $ajeno = User::factory()->rol('docente')->create();

    $this->actingAs($ajeno)->get(route('disponibilidad.index', ['fecha' => '2026-12-01']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendario.dias.2026-12-15.0.actividad', 'Reservado'));
});
