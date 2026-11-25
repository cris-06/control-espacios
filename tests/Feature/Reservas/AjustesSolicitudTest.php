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
    $reserva = ($this->crear)();

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)([
        'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => '2026-11-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20]],
        'enviar' => true,
    ]))
        ->assertRedirect(route('reservas.edit', $reserva))
        ->assertSessionHasErrors(['reserva.0', 'detalles.0.fecha']);

    expect($reserva->refresh()->estado_id)->toBe(E::BORRADOR);
});

test('a coordinator sending to administration gets the strict errors under each field', function () {
    $reserva = ($this->crear)($this->coordinador);
    $this->espacio->update(['estatus' => 'mantenimiento']);

    $this->actingAs($this->coordinador)->put(route('reservas.update', $reserva), ($this->datos)([
        'enviar' => true,
    ]))
        ->assertRedirect(route('reservas.edit', $reserva))
        ->assertSessionHasErrors(['reserva.0', 'detalles.0.espacio_id' => 'El espacio no está disponible (inactivo o en mantenimiento).']);
});

// --- Formato 10: nombre, objetivo y alumnos obligatorios; mínimo y máximo del espacio ---

test('name, objective and number of students are required from the first save', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([
        'nombre_actividad' => '   ',
        'objetivo' => '',
        'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => null]],
    ]))->assertSessionHasErrors([
        'nombre_actividad' => 'Escribe el nombre de la actividad.',
        'objetivo' => 'Escribe el objetivo de la actividad.',
        'detalles.0.cantidad_alumnos' => 'Indica la cantidad de alumnos.',
    ]);

    expect(Reserva::query()->count())->toBe(0);
});

test('fewer students than the space minimum are rejected under that field', function () {
    $this->espacio->update(['minimo_alumnos' => 5, 'maximo_alumnos' => 30]);
    $bloque = fn (int $alumnos): array => [['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => $alumnos]];

    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)(['detalles' => $bloque(4)]))
        ->assertSessionHasErrors(['detalles.0.cantidad_alumnos' => "Son 4 alumnos y {$this->espacio->nombre} requiere como mínimo 5."]);
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)(['detalles' => $bloque(31)]))
        ->assertSessionHasErrors(['detalles.0.cantidad_alumnos' => "Son 31 alumnos y {$this->espacio->nombre} admite como máximo 30."]);
    expect(Reserva::query()->count())->toBe(0);

    // Los límites exactos son válidos.
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)(['detalles' => $bloque(5)]))->assertSessionHasNoErrors();
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)(['detalles' => $bloque(30)]))->assertSessionHasNoErrors();
});

test('a teacher sending to coordination from the detail page is checked against the current limits', function () {
    $reserva = ($this->crear)();
    $this->espacio->update(['minimo_alumnos' => 25]);

    $this->actingAs($this->docente)->post(route('reservas.transicion', $reserva), ['accion' => W::ENVIAR])
        ->assertSessionHasErrors([
            'reserva.0' => "Espacio 1: son 20 alumnos y {$this->espacio->nombre} requiere como mínimo 25.",
            'detalles.0.cantidad_alumnos' => "Son 20 alumnos y {$this->espacio->nombre} requiere como mínimo 25.",
        ]);

    expect($reserva->refresh()->estado_id)->toBe(E::BORRADOR)
        ->and($reserva->fecha_entrega_formato)->toBeNull();
});

test('the minimum is checked again when sending if the space changed after saving', function () {
    $reserva = ($this->crear)($this->coordinador);
    $this->espacio->update(['minimo_alumnos' => 25]);

    $this->actingAs($this->coordinador)->post(route('reservas.transicion', $reserva), ['accion' => W::ENVIAR])
        ->assertSessionHasErrors(['reserva.0', 'detalles.0.cantidad_alumnos']);

    expect($reserva->refresh()->estado_id)->toBe(E::BORRADOR);
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

// --- Filtro de estados de Solicitudes según el rol ---

test('each role is offered only its own states in the Solicitudes filter', function () {
    $ids = fn (User $usuario): array => collect(
        $this->actingAs($usuario)->get(route('solicitudes.index'))->assertOk()
            ->viewData('page')['props']['opcionesEstados'],
    )->pluck('id')->all();

    $etapaDocente = [E::BORRADOR, E::PENDIENTE_COORDINADOR, E::CORRECCION, E::AUTORIZADA, E::DENEGADA, E::CANCELADA];

    expect($ids($this->docente))->toBe($etapaDocente)
        ->and($ids($this->coordinador))->toBe($etapaDocente)
        ->and($ids($this->coordinador))->not->toContain(E::PENDIENTE_ADMIN)
        ->and($ids($this->admin))->toBe([E::CORRECCION, E::PENDIENTE_ADMIN, E::AUTORIZADA, E::DENEGADA, E::CANCELADA])
        ->and($ids($this->superadmin))->toBe([E::BORRADOR, E::PENDIENTE_COORDINADOR, E::CORRECCION, E::PENDIENTE_ADMIN, E::AUTORIZADA, E::DENEGADA, E::CANCELADA]);
});

test('a state outside the role is ignored as a filter, but the list still shows every visible request', function () {
    $reserva = ($this->crear)($this->coordinador);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR);
    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN);

    $this->actingAs($this->coordinador)->get(route('solicitudes.index', ['estado_id' => E::PENDIENTE_ADMIN]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filtros.estado_id', null)
            ->where('solicitudes.0.id', $reserva->id)
            ->where('solicitudes.0.estado_id', E::PENDIENTE_ADMIN));
});

// --- Tabla de horarios del día (Disponibilidad) ---

test('the calendar sends each block with its space and the list of spaces for the day table', function () {
    $reserva = ($this->crear)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $fuera = Espacio::factory()->create(['nombre' => 'Zona en obra', 'estatus' => 'mantenimiento']);

    $this->actingAs($this->docente)->get(route('disponibilidad.index', ['fecha' => '2026-12-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('puedeCrear', true)
            ->where('calendario.dias.2026-12-15.0.espacio_id', $this->espacio->id)
            ->where('calendario.espacios', fn ($espacios) => collect($espacios)->firstWhere('id', $this->espacio->id)['disponible'] === true
                && collect($espacios)->firstWhere('id', $fuera->id)['disponible'] === false));

    $this->actingAs($this->admin)->get(route('disponibilidad.index'))
        ->assertInertia(fn (Assert $page) => $page->where('puedeCrear', false));
});

test('reserving from the day table opens the form with the space, date and time already chosen', function () {
    $this->actingAs($this->docente)
        ->get(route('reservas.create', ['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '09:00', 'hora_fin' => '10:00']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reservas/Form')
            ->where('espacioPreseleccionado', $this->espacio->id)
            ->where('bloquePreseleccionado', ['fecha' => '2026-12-15', 'hora_inicio' => '09:00', 'hora_fin' => '10:00']));

    // Datos inválidos en la URL no rellenan nada (y no rompen el formulario).
    $this->actingAs($this->docente)
        ->get(route('reservas.create', ['fecha' => '15/12/2026', 'hora_inicio' => '11:00', 'hora_fin' => '10:00']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('bloquePreseleccionado', null));
});
