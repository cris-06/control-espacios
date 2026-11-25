<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Campos del Formato 10 que se capturan una vez por solicitud (2026_11_24_000001): folio del
 * coordinador, docente y coordinador responsables, cantidad total de alumnos, descripción y
 * observaciones generales. Se guardan, se recuperan al editar, se validan con las reglas de la
 * licenciatura y aparecen en la impresión, sin tocar los folios ni las fechas automáticas.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lic = Licenciatura::factory()->create(['nombre' => 'Sistemas', 'siglas' => 'LSC']);
    $this->otra = Licenciatura::factory()->create(['nombre' => 'Derecho']);
    $this->docente = User::factory()->rol('docente')->create(['nombre' => 'Ana', 'apellido_paterno' => 'Docente']);
    $this->docente->licenciaturas()->attach($this->lic->id);
    $this->colega = User::factory()->rol('docente')->create(['nombre' => 'Beto', 'apellido_paterno' => 'Colega']);
    $this->colega->licenciaturas()->attach($this->lic->id);
    $this->ajeno = User::factory()->rol('docente')->create();
    $this->ajeno->licenciaturas()->attach($this->otra->id);
    $this->coordinador = User::factory()->rol('coordinador')->create(['nombre' => 'Carla', 'apellido_paterno' => 'Coord']);
    $this->coordinador->licenciaturas()->attach($this->lic->id);
    $this->coordinador2 = User::factory()->rol('coordinador')->create(['nombre' => 'Diego', 'apellido_paterno' => 'Coord']);
    $this->coordinador2->licenciaturas()->attach($this->lic->id);
    $this->coordinadorAjeno = User::factory()->rol('coordinador')->create();
    $this->coordinadorAjeno->licenciaturas()->attach($this->otra->id);
    $this->admin = User::factory()->rol('admin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40, 'minimo_alumnos' => 1, 'maximo_alumnos' => 40]);
    $this->workflow = app(W::class);

    $this->datos = fn (array $extra = []): array => [
        'licenciatura_id' => $this->lic->id, 'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Jornada de ciencia', 'objetivo' => 'Divulgar proyectos',
        'detalles' => [
            ['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 30],
            ['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-16', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 25],
        ],
        ...$extra,
    ];
    $this->campos = [
        'docente_responsable_id' => null, // se llena en cada prueba
        'coordinador_id' => null,
        'cantidad_total_alumnos' => 35,
        'descripcion_actividad' => "Bienvenida.\nPresentación de proyectos.\nClausura.",
        'observaciones' => 'Jueces: Dra. Pérez y Mtro. López.',
        'costo_alumno' => 0,
    ];
    $this->crear = function (array $extra = [], ?User $autor = null): Reserva {
        $this->actingAs($autor ?? $this->docente)->post(route('reservas.store'), ($this->datos)($extra))
            ->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };
});

test('the new Formato 10 fields are saved and the system folios and dates stay automatic', function () {
    $reserva = ($this->crear)([...$this->campos, 'docente_responsable_id' => $this->colega->id, 'coordinador_id' => $this->coordinador2->id]);

    expect($reserva->docente_responsable_id)->toBe($this->colega->id)
        ->and($reserva->coordinador_id)->toBe($this->coordinador2->id)
        ->and($reserva->cantidad_total_alumnos)->toBe(35)
        ->and($reserva->descripcion_actividad)->toBe("Bienvenida.\nPresentación de proyectos.\nClausura.")
        ->and($reserva->observaciones)->toBe('Jueces: Dra. Pérez y Mtro. López.')
        ->and($reserva->costo_alumno)->toBe('0.00')
        // Los folios del sistema siguen siendo automáticos; tipo_formato queda en su valor interno.
        ->and($reserva->tipo_formato)->toBe('normal')
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and($reserva->folio_institucional)->toBe(sprintf('LSC-20261112-%05d', $reserva->id))
        ->and($reserva->fecha_entrega_formato)->toBeNull();
});

test('the browser cannot set the system folios or dates through the new fields', function () {
    $reserva = ($this->crear)([
        ...$this->campos,
        'folio' => 'RES-99999', 'folio_institucional' => 'XXX-1', 'fecha_entrega_formato' => '2020-01-01',
    ]);

    expect($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and($reserva->folio_institucional)->toBe(sprintf('LSC-20261112-%05d', $reserva->id))
        ->and($reserva->fecha_entrega_formato)->toBeNull();
});

test('the edit form receives the new fields and the coordinators of the program', function () {
    $reserva = ($this->crear)([...$this->campos, 'docente_responsable_id' => $this->colega->id, 'coordinador_id' => $this->coordinador2->id]);

    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('reserva.folio_coordinador')
            ->missing('reserva.tipo_formato')
            ->where('reserva.docente_responsable_id', $this->colega->id)
            ->where('reserva.coordinador_id', $this->coordinador2->id)
            ->where('reserva.cantidad_total_alumnos', 35)
            ->where('reserva.descripcion_actividad', "Bienvenida.\nPresentación de proyectos.\nClausura.")
            ->where('reserva.observaciones', 'Jueces: Dra. Pérez y Mtro. López.')
            ->has('opciones.coordinadores', 2)
            ->where('opciones.coordinadores.0.id', $this->coordinador->id)
            ->where('opciones.coordinadores.1.id', $this->coordinador2->id));
});

test('responsible people must belong to the program of the request', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([
        'docente_responsable_id' => $this->ajeno->id,
        'coordinador_id' => $this->coordinadorAjeno->id,
    ]))->assertSessionHasErrors([
        'docente_responsable_id' => 'El docente responsable no pertenece a la licenciatura de la reserva.',
        'coordinador_id' => 'El coordinador responsable debe ser un coordinador activo de la licenciatura.',
    ]);

    // Un docente no puede figurar como coordinador responsable.
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([
        'coordinador_id' => $this->colega->id,
    ]))->assertSessionHasErrors('coordinador_id');

    expect(Reserva::query()->count())->toBe(0);
});

test('the total of students is not a sum but cannot be lower than any space', function () {
    // 30 + 25 en dos espacios: el total no se suma (pueden ser los mismos alumnos)…
    ($this->crear)(['cantidad_total_alumnos' => 30]);

    // …pero no puede ser menor que el espacio con más alumnos.
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)(['cantidad_total_alumnos' => 29]))
        ->assertSessionHasErrors(['cantidad_total_alumnos' => 'La cantidad total no puede ser menor que la de un espacio (30 alumnos).']);

    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)(['cantidad_total_alumnos' => 0]))
        ->assertSessionHasErrors('cantidad_total_alumnos');

    expect(Reserva::query()->count())->toBe(1);
});

test('a free activity accepts cost zero and a negative cost is rejected', function () {
    expect(($this->crear)(['costo_alumno' => 0])->costo_alumno)->toBe('0.00');

    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)(['costo_alumno' => -5]))
        ->assertSessionHasErrors(['costo_alumno' => 'El costo por alumno no puede ser negativo (usa 0 si es gratuita).']);
});

test('without a choice the system assigns the coordinator, and a coordinator creating it is assigned himself', function () {
    expect(($this->crear)()->coordinador_id)->toBe($this->coordinador->id);

    // El coordinador que llena la solicitud queda como coordinador aunque elija a otro.
    $propia = ($this->crear)(['coordinador_id' => $this->coordinador2->id], $this->coordinador);
    expect($propia->coordinador_id)->toBe($this->coordinador->id);
});

test('the director cannot change the responsible coordinator', function () {
    $reserva = ($this->crear)(['coordinador_id' => $this->coordinador2->id]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->coordinador2, W::APROBAR);

    $this->actingAs($this->admin)->put(route('reservas.update', $reserva), ($this->datos)([
        'coordinador_id' => $this->coordinador->id,
    ]))->assertSessionHasErrors(['coordinador_id' => 'El directivo no puede cambiar el coordinador responsable de la solicitud.']);

    expect($reserva->refresh()->coordinador_id)->toBe($this->coordinador2->id);
});

test('the coordinator edit history names the new fields that changed', function () {
    $reserva = ($this->crear)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    $this->actingAs($this->coordinador)->put(route('reservas.update', $reserva), ($this->datos)([
        'cantidad_total_alumnos' => 31, 'observaciones' => 'Invitados externos',
    ]))->assertSessionHasNoErrors();

    $comentario = $reserva->historial()->where('accion', HistorialReserva::EDITADA_COORDINADOR)->value('comentario');
    expect($comentario)->toContain('cantidad total de alumnos')->toContain('observaciones');
});

test('the printable Formato 10 shows the new fields without losing the per space data', function () {
    $reserva = ($this->crear)([
        ...$this->campos,
        'docente_responsable_id' => $this->colega->id,
        'coordinador_id' => $this->coordinador2->id,
        'detalles' => [
            ['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 30,
                'material_requerido' => 'Mesa de registro', 'observaciones' => 'Acceso por la puerta norte'],
        ],
    ]);

    $this->actingAs($this->docente)->get(route('reservas.imprimir', $reserva))->assertOk()
        ->assertDontSee('Folio del coordinador')
        ->assertDontSee('Extracurricular')
        ->assertSee('Docente responsable de la actividad:')
        ->assertSee('Beto Colega')
        ->assertSee('Coordinador responsable:')
        ->assertSee('Diego Coord')
        ->assertSee('35 alumnos')
        ->assertSee("Bienvenida.\nPresentación de proyectos.\nClausura.", false)
        ->assertSee('Jueces: Dra. Pérez y Mtro. López.')
        // Lo de cada espacio se conserva.
        ->assertSee('Mesa de registro')
        ->assertSee('Acceso por la puerta norte')
        ->assertSee('FORMATO 10 DE ACTIVIDADES O EVENTOS');
});

test('old reservations without the new fields keep printing as before', function () {
    $reserva = ($this->crear)();

    $this->actingAs($this->docente)->get(route('reservas.imprimir', $reserva))->assertOk()
        ->assertSee('Suma por bloque: 55')
        ->assertSee('Sin observaciones');
});
