<?php

use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Fase 6: restricciones por licenciatura en listados, reportes, disponibilidad y panel,
 * más las pruebas pedidas (coordinador sin acceso a otra licenciatura, docente que no
 * autoriza y transición inválida rechazada).
 */
beforeEach(function () {
    $this->sistemas = Licenciatura::factory()->create(['nombre' => 'Sistemas']);
    $this->derecho = Licenciatura::factory()->create(['nombre' => 'Derecho']);

    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->sistemas);
    $this->docenteDerecho = User::factory()->rol('docente')->create();
    $this->docenteDerecho->licenciaturas()->attach($this->derecho);

    $this->coordSistemas = User::factory()->rol('coordinador')->create();
    $this->coordSistemas->licenciaturas()->attach($this->sistemas);
    $this->coordDerecho = User::factory()->rol('coordinador')->create();
    $this->coordDerecho->licenciaturas()->attach($this->derecho);

    $this->admin = User::factory()->rol('admin')->create();
    $this->espacio = Espacio::factory()->create(['nombre' => 'Auditorio']);
    $this->manana = Date::tomorrow()->toDateString();

    $this->crear = fn (User $creador, Licenciatura $licenciatura, int $estado, array $detalle = []) => Reserva::factory()
        ->enEstado($estado)
        ->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => $this->manana, ...$detalle])
        ->create(['usuario_creador_id' => $creador->id, 'licenciatura_id' => $licenciatura->id, 'nombre_actividad' => 'Actividad '.$licenciatura->nombre]);

    $this->deSistemas = ($this->crear)($this->docente, $this->sistemas, E::PENDIENTE_COORDINADOR);
    $this->deDerecho = ($this->crear)($this->docenteDerecho, $this->derecho, E::PENDIENTE_COORDINADOR, ['hora_inicio' => '15:00:00', 'hora_fin' => '16:00:00']);
});

// --- Las tres pruebas pedidas explícitamente ---

test('a coordinator cannot access another program, neither in listings nor by URL', function () {
    $this->actingAs($this->coordSistemas);

    $this->get(route('solicitudes.index', ['licenciatura_id' => $this->derecho->id]))->assertForbidden();
    $this->get(route('reportes.index', ['licenciatura_id' => $this->derecho->id]))->assertForbidden();
    $this->get(route('reservas.show', $this->deDerecho))->assertForbidden();
    $this->get(route('reservas.edit', $this->deDerecho))->assertForbidden();
    $this->post(route('reservas.transicion', $this->deDerecho), ['accion' => 'aprobar'])->assertForbidden();

    expect($this->deDerecho->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

test('a teacher cannot authorize', function () {
    $propia = ($this->crear)($this->docente, $this->sistemas, E::PENDIENTE_ADMIN, ['hora_inicio' => '17:00:00', 'hora_fin' => '18:00:00']);

    $this->actingAs($this->docente)
        ->post(route('reservas.transicion', $propia), ['accion' => 'autorizar', 'observacion' => 'Me la autorizo'])
        ->assertForbidden();

    expect($propia->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($propia->historial()->count())->toBe(0);
});

test('an invalid transition is rejected', function () {
    $this->actingAs($this->admin)
        ->post(route('reservas.transicion', $this->deSistemas), ['accion' => 'autorizar', 'observacion' => 'Saltándose a coordinación'])
        ->assertSessionHasErrors('transicion');

    expect($this->deSistemas->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

// --- Listados ---

test('each role lists only its scope', function () {
    $ids = fn (User $u, array $q = []) => collect(
        $this->actingAs($u)->get(route('solicitudes.index', $q))->viewData('page')['props']['solicitudes']
    )->pluck('id')->sort()->values()->all();

    expect($ids($this->docente))->toBe([$this->deSistemas->id])
        ->and($ids($this->coordSistemas))->toBe([$this->deSistemas->id])
        ->and($ids($this->coordDerecho))->toBe([$this->deDerecho->id])
        ->and($ids($this->admin))->toBe([$this->deSistemas->id, $this->deDerecho->id])
        ->and($ids($this->admin, ['licenciatura_id' => $this->derecho->id]))->toBe([$this->deDerecho->id])
        ->and($ids($this->coordSistemas, ['licenciatura_id' => $this->sistemas->id]))->toBe([$this->deSistemas->id]);
});

test('a teacher cannot filter by a program they do not belong to', function () {
    $this->actingAs($this->docente)
        ->get(route('solicitudes.index', ['licenciatura_id' => $this->derecho->id]))
        ->assertForbidden();
});

test('only own programs are offered as filters', function () {
    $this->actingAs($this->coordSistemas)->get(route('solicitudes.index'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('opcionesLicenciaturas', [['id' => $this->sistemas->id, 'nombre' => 'Sistemas']]));

    $this->actingAs($this->admin)->get(route('reportes.index'))
        ->assertInertia(fn (Assert $pagina) => $pagina->has('opciones.licenciaturas', 2));
});

// --- Reportes ---

test('reports only include the coordinator\'s programs', function () {
    $this->actingAs($this->coordSistemas)->get(route('reportes.index'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('resultados', 1)
            ->where('resultados.0.reserva_id', $this->deSistemas->id)
            ->where('totalesEstado.0.nombre', 'Pendiente de coordinador'));
});

test('administration reports filter by program and add up by status', function () {
    ($this->crear)($this->docenteDerecho, $this->derecho, E::AUTORIZADA, ['hora_inicio' => '08:00:00', 'hora_fin' => '09:00:00', 'estado_detalle' => ReservaDetalle::AUTORIZADA]);

    $this->actingAs($this->admin)->get(route('reportes.index', ['licenciatura_id' => $this->derecho->id]))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('resultados', 2)
            ->has('totalesEstado', 2)
            ->where('totalesEstado.0.nombre', 'Pendiente de coordinador')
            ->where('totalesEstado.1.nombre', 'Autorizada')
            ->where('totalesEspacio.0', ['nombre' => 'Auditorio', 'total' => 2]));
});

// --- Disponibilidad ---

test('availability shows every booking but hides the details of reservations you cannot see', function () {
    $this->actingAs($this->coordSistemas)
        ->get(route('disponibilidad.index', ['fecha' => $this->manana]))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('espacios', 1)
            ->has('espacios.0.bloques', 2)
            ->where('espacios.0.bloques.0.actividad', 'Actividad Sistemas')
            ->where('espacios.0.bloques.0.visible', true)
            ->where('espacios.0.bloques.1.actividad', 'Reservado')
            ->where('espacios.0.bloques.1.docente', '—')
            ->where('espacios.0.bloques.1.visible', false)
            ->where('espacios.0.bloques.1.reserva_id', null));
});

test('availability tells apart occupied, pending, free and out of service spaces', function () {
    $libre = Espacio::factory()->create(['nombre' => 'Biblioteca']);
    Espacio::factory()->create(['nombre' => 'Cancha', 'estatus' => 'mantenimiento']);
    Espacio::factory()->create(['nombre' => 'Sala de juntas', 'estatus' => 'inactivo']);
    $ocupado = Espacio::factory()->create(['nombre' => 'Aula magna']);
    ($this->crear)($this->docente, $this->sistemas, E::AUTORIZADA, ['espacio_id' => $ocupado->id, 'hora_inicio' => '11:00:00', 'hora_fin' => '13:00:00', 'estado_detalle' => ReservaDetalle::AUTORIZADA]);

    // El Auditorio tiene una reserva pendiente de 10:00 a 12:00.
    $situaciones = collect($this->actingAs($this->admin)
        ->get(route('disponibilidad.index', ['fecha' => $this->manana, 'hora_inicio' => '11:30', 'hora_fin' => '12:30']))
        ->viewData('page')['props']['espacios'])
        ->mapWithKeys(fn (array $e) => [$e['nombre'] => $e['situacion']]);

    expect($situaciones->all())->toBe([
        'Auditorio' => 'pendiente',
        'Aula magna' => 'ocupado',
        'Biblioteca' => 'libre',
        'Cancha' => 'fuera_de_servicio',
        'Sala de juntas' => 'fuera_de_servicio',
    ]);
});

test('a reservation ending exactly when the search starts is not a conflict', function () {
    $this->actingAs($this->admin)
        ->get(route('disponibilidad.index', ['fecha' => $this->manana, 'espacio_id' => $this->espacio->id, 'hora_inicio' => '12:00', 'hora_fin' => '13:00']))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('espacios', 1)
            ->where('espacios.0.situacion', 'libre'));
});

test('drafts and denied reservations do not occupy a space', function () {
    $otro = Espacio::factory()->create();
    ($this->crear)($this->docente, $this->sistemas, E::BORRADOR, ['espacio_id' => $otro->id]);
    ($this->crear)($this->docente, $this->sistemas, E::DENEGADA, ['espacio_id' => $otro->id, 'estado_detalle' => ReservaDetalle::DENEGADA]);

    $this->actingAs($this->admin)
        ->get(route('disponibilidad.index', ['fecha' => $this->manana, 'espacio_id' => $otro->id]))
        ->assertInertia(fn (Assert $pagina) => $pagina->has('espacios.0.bloques', 0));
});

// --- Panel ---

test('the dashboard counts and lists only what each role may see', function () {
    $this->actingAs($this->coordSistemas)->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('indicadores.0.titulo', 'Por revisar')
            ->where('indicadores.0.valor', 1)
            ->has('pendientes', 1)
            ->where('pendientes.0.id', $this->deSistemas->id));

    $this->actingAs($this->admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('indicadores.1.titulo', 'Con coordinación')
            ->where('indicadores.1.valor', 2)
            ->has('pendientes', 0));
});

test('upcoming authorized reservations appear on the dashboard of whom they concern', function () {
    $reserva = ($this->crear)($this->docente, $this->sistemas, E::PENDIENTE_ADMIN, ['hora_inicio' => '18:00:00', 'hora_fin' => '19:00:00']);
    app(W::class)->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Adelante');

    $this->actingAs($this->docente)->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('proximas', 1)
            ->where('proximas.0.reserva_id', $reserva->id));

    $this->actingAs($this->docenteDerecho)->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina->has('proximas', 0));
});

// --- coordinador_id según la licenciatura ---

test('the coordinator is assigned from the program when creating', function () {
    $this->actingAs($this->docenteDerecho)->post(route('reservas.store'), [
        'licenciatura_id' => $this->derecho->id,
        'actividad_id' => $this->deDerecho->actividad_id,
        'detalles' => [],
    ])->assertSessionHasNoErrors();

    expect(Reserva::latest('id')->first()->coordinador_id)->toBe($this->coordDerecho->id);
});
