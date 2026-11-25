<?php

use App\Models\Actividad;
use App\Models\Cuatrimestre;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\ReservaLicenciatura;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * «Agregar licenciatura y cuatrimestre» e «Invitar licenciatura» (2026_11_24_000002): bloques
 * adicionales de licenciatura con sus cuatrimestres y grupos. Son informativos: la licenciatura
 * principal sigue determinando coordinador, permisos, folio institucional y flujo.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->sistemas = Licenciatura::factory()->create(['nombre' => 'Sistemas', 'siglas' => 'LSC']);
    $this->industrial = Licenciatura::factory()->create(['nombre' => 'Industrial', 'siglas' => 'LII']);
    $this->derecho = Licenciatura::factory()->create(['nombre' => 'Derecho', 'siglas' => 'LDE']);
    $this->psicologia = Licenciatura::factory()->create(['nombre' => 'Psicología', 'siglas' => 'LPS']);
    $this->inactiva = Licenciatura::factory()->create(['nombre' => 'Antigua', 'estatus' => 'inactiva']);

    // El docente tiene Sistemas e Industrial; Derecho y Psicología solo pueden invitarse.
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach([$this->sistemas->id, $this->industrial->id]);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->sistemas->id);
    $this->coordinadorDerecho = User::factory()->rol('coordinador')->create();
    $this->coordinadorDerecho->licenciaturas()->attach($this->derecho->id);
    $this->coordinadorIndustrial = User::factory()->rol('coordinador')->create();
    $this->coordinadorIndustrial->licenciaturas()->attach($this->industrial->id);
    $this->docenteDerecho = User::factory()->rol('docente')->create();
    $this->docenteDerecho->licenciaturas()->attach($this->derecho->id);
    $this->admin = User::factory()->rol('admin')->create();

    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40, 'minimo_alumnos' => 1, 'maximo_alumnos' => 40]);
    $this->c1 = Cuatrimestre::query()->where('numero', 1)->value('id') ?? Cuatrimestre::factory()->create(['numero' => 1])->id;
    $this->c3 = Cuatrimestre::query()->where('numero', 3)->value('id') ?? Cuatrimestre::factory()->create(['numero' => 3])->id;
    $this->workflow = app(W::class);

    $this->datos = fn (array $extra = []): array => [
        'licenciatura_id' => $this->sistemas->id, 'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Feria de proyectos', 'objetivo' => 'Presentar proyectos integradores',
        'cuatrimestres' => [['id' => $this->c1, 'grupos' => ['A']]],
        'detalles' => [
            ['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 30],
        ],
        ...$extra,
    ];
    $this->adicionales = fn (): array => [
        ['licenciatura_id' => $this->industrial->id, 'tipo' => 'participante', 'cuatrimestres' => [['id' => $this->c3, 'grupos' => ['b', 'A']]]],
        ['licenciatura_id' => $this->derecho->id, 'tipo' => 'invitada', 'cuatrimestres' => [['id' => $this->c1, 'grupos' => ['C']]]],
        ['licenciatura_id' => $this->psicologia->id, 'tipo' => 'invitada', 'cuatrimestres' => []],
    ];
    $this->crear = function (array $extra = []): Reserva {
        $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)($extra))
            ->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };
});

test('participating and invited programs are saved in order with their groups', function () {
    $reserva = ($this->crear)(['licenciaturas_adicionales' => ($this->adicionales)()]);

    $filas = $reserva->licenciaturasAdicionales()->get();

    expect($filas)->toHaveCount(3)
        ->and($filas->pluck('licenciatura_id')->all())->toBe([$this->industrial->id, $this->derecho->id, $this->psicologia->id])
        ->and($filas->pluck('tipo')->all())->toBe([ReservaLicenciatura::PARTICIPANTE, ReservaLicenciatura::INVITADA, ReservaLicenciatura::INVITADA])
        ->and($filas->pluck('orden')->all())->toBe([0, 1, 2])
        // Los grupos se guardan en mayúsculas y ordenados, como los de la licenciatura principal.
        ->and($filas[0]->cuatrimestres)->toBe([['id' => $this->c3, 'grupos' => ['A', 'B']]])
        ->and($filas[1]->cuatrimestres)->toBe([['id' => $this->c1, 'grupos' => ['C']]])
        ->and($filas[2]->cuatrimestres)->toBeNull()
        // La principal no cambia: folio institucional, coordinador y licenciatura.
        ->and($reserva->licenciatura_id)->toBe($this->sistemas->id)
        ->and($reserva->coordinador_id)->toBe($this->coordinador->id)
        ->and($reserva->folio_institucional)->toStartWith('LSC-')
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id));
});

test('a request without additional programs keeps working as before', function () {
    $reserva = ($this->crear)();

    expect($reserva->licenciaturasAdicionales()->count())->toBe(0)
        ->and($reserva->cuatrimestres)->toBe([['id' => $this->c1, 'grupos' => ['A']]]);
});

test('duplicated, principal, inactive or foreign programs are rejected', function () {
    $post = fn (array $adicionales) => $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->datos)(['licenciaturas_adicionales' => $adicionales]));

    $post([['licenciatura_id' => $this->sistemas->id, 'tipo' => 'participante', 'cuatrimestres' => []]])
        ->assertSessionHasErrors(['licenciaturas_adicionales.0.licenciatura_id' => 'Esa es la licenciatura principal de la solicitud; no se agrega de nuevo.']);

    $post([
        ['licenciatura_id' => $this->derecho->id, 'tipo' => 'invitada', 'cuatrimestres' => []],
        ['licenciatura_id' => $this->derecho->id, 'tipo' => 'invitada', 'cuatrimestres' => []],
    ])->assertSessionHasErrors(['licenciaturas_adicionales.1.licenciatura_id' => 'La licenciatura ya está agregada.']);

    $post([['licenciatura_id' => $this->inactiva->id, 'tipo' => 'invitada', 'cuatrimestres' => []]])
        ->assertSessionHasErrors(['licenciaturas_adicionales.0.licenciatura_id' => 'La licenciatura no existe o no está activa.']);

    // Como participante solo se agregan licenciaturas propias; las demás se invitan.
    $post([['licenciatura_id' => $this->derecho->id, 'tipo' => 'participante', 'cuatrimestres' => []]])
        ->assertSessionHasErrors(['licenciaturas_adicionales.0.licenciatura_id' => 'Solo puedes agregar como participantes tus licenciaturas. Usa «Invitar licenciatura» para las demás.']);

    $post([['licenciatura_id' => $this->derecho->id, 'tipo' => 'invitada', 'cuatrimestres' => [
        ['id' => $this->c1, 'grupos' => ['A']], ['id' => $this->c1, 'grupos' => ['B']],
    ]]])->assertSessionHasErrors(['licenciaturas_adicionales.0.cuatrimestres' => 'Un cuatrimestre está repetido en esta licenciatura.']);

    $post([['licenciatura_id' => $this->derecho->id, 'tipo' => 'otra', 'cuatrimestres' => []]])
        ->assertSessionHasErrors('licenciaturas_adicionales.0.tipo');

    $post([['licenciatura_id' => $this->derecho->id, 'tipo' => 'invitada', 'cuatrimestres' => [['id' => $this->c1, 'grupos' => ['1']]]]])
        ->assertSessionHasErrors('licenciaturas_adicionales.0.cuatrimestres.0.grupos.0');

    expect(Reserva::query()->count())->toBe(0);
});

test('the edit form recovers the additional programs and removing one deletes it', function () {
    $reserva = ($this->crear)(['licenciaturas_adicionales' => ($this->adicionales)()]);

    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reserva.licenciaturas_adicionales', 3)
            ->where('reserva.licenciaturas_adicionales.0.licenciatura_id', $this->industrial->id)
            ->where('reserva.licenciaturas_adicionales.0.tipo', 'participante')
            ->where('reserva.licenciaturas_adicionales.0.cuatrimestres', [['id' => $this->c3, 'grupos' => ['A', 'B']]])
            ->where('reserva.licenciaturas_adicionales.1.tipo', 'invitada')
            ->where('reserva.licenciaturas_adicionales.1.nombre', 'Derecho')
            // Cualquier licenciatura activa se puede invitar; las inactivas no se ofrecen.
            ->where('opciones.licenciaturas_invitables', fn ($lista) => collect($lista)->pluck('id')->contains($this->psicologia->id)
                && ! collect($lista)->pluck('id')->contains($this->inactiva->id)));

    // «Quitar» la invitada de Derecho y cambiar los grupos de Industrial.
    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)([
        'licenciaturas_adicionales' => [
            ['licenciatura_id' => $this->industrial->id, 'tipo' => 'participante', 'cuatrimestres' => [['id' => $this->c3, 'grupos' => ['C']]]],
            ['licenciatura_id' => $this->psicologia->id, 'tipo' => 'invitada', 'cuatrimestres' => []],
        ],
    ]))->assertSessionHasNoErrors();

    $filas = $reserva->licenciaturasAdicionales()->get();
    expect($filas->pluck('licenciatura_id')->all())->toBe([$this->industrial->id, $this->psicologia->id])
        ->and($filas[0]->cuatrimestres)->toBe([['id' => $this->c3, 'grupos' => ['C']]])
        ->and(ReservaLicenciatura::query()->count())->toBe(2);

    // Sin enviar la lista (cliente anterior), las licenciaturas adicionales se conservan.
    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)())->assertSessionHasNoErrors();
    expect($reserva->licenciaturasAdicionales()->count())->toBe(2);

    // Lista vacía: se quitan todas.
    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)(['licenciaturas_adicionales' => []]))->assertSessionHasNoErrors();
    expect($reserva->licenciaturasAdicionales()->count())->toBe(0);
});

test('a participating program already saved can be kept by a coordinator who does not have it', function () {
    $reserva = ($this->crear)(['licenciaturas_adicionales' => [
        ['licenciatura_id' => $this->industrial->id, 'tipo' => 'participante', 'cuatrimestres' => []],
    ]]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    // El coordinador de Sistemas no tiene Industrial, pero ya participaba: puede corregir sin quitarla.
    $this->actingAs($this->coordinador)->put(route('reservas.update', $reserva->refresh()), ($this->datos)([
        'licenciaturas_adicionales' => [
            ['licenciatura_id' => $this->industrial->id, 'tipo' => 'participante', 'cuatrimestres' => [['id' => $this->c1, 'grupos' => ['A']]]],
        ],
    ]))->assertSessionHasNoErrors();

    $comentario = $reserva->historial()->where('accion', HistorialReserva::EDITADA_COORDINADOR)->value('comentario');
    expect($comentario)->toContain('licenciaturas participantes e invitadas')
        ->and($reserva->licenciaturasAdicionales()->value('cuatrimestres'))->toBe([['id' => $this->c1, 'grupos' => ['A']]]);
});

test('invited or participating programs do not give permissions, visibility or approval', function () {
    $reserva = ($this->crear)(['licenciaturas_adicionales' => ($this->adicionales)()]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $reserva->refresh();

    expect($reserva->estado_id)->toBe(E::PENDIENTE_COORDINADOR);

    foreach ([$this->coordinadorDerecho, $this->coordinadorIndustrial, $this->docenteDerecho] as $ajeno) {
        $this->actingAs($ajeno)->get(route('reservas.show', $reserva))->assertForbidden();
        $this->actingAs($ajeno)->get(route('reservas.edit', $reserva))->assertForbidden();
        $this->actingAs($ajeno)->post(route('reservas.transicion', $reserva), ['accion' => W::APROBAR])->assertForbidden();

        expect(Reserva::query()->visiblesPara($ajeno)->whereKey($reserva->id)->exists())->toBeFalse();
    }

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);

    // El coordinador de la principal sigue aprobando y el directivo autorizando, como siempre.
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->admin, W::AUTORIZAR, 'Autorizada');
    expect($reserva->refresh()->estado_id)->toBe(E::AUTORIZADA)
        ->and($reserva->licenciaturasAdicionales()->count())->toBe(3);
});

test('the director cannot change the additional programs list into the principal one', function () {
    $reserva = ($this->crear)(['licenciaturas_adicionales' => [['licenciatura_id' => $this->derecho->id, 'tipo' => 'invitada', 'cuatrimestres' => []]]]);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->coordinador, W::APROBAR);

    $this->actingAs($this->admin)->put(route('reservas.update', $reserva), ($this->datos)([
        'licenciatura_id' => $this->derecho->id,
        'licenciaturas_adicionales' => [['licenciatura_id' => $this->sistemas->id, 'tipo' => 'invitada', 'cuatrimestres' => []]],
    ]))->assertSessionHasErrors('licenciatura_id');

    expect($reserva->refresh()->licenciatura_id)->toBe($this->sistemas->id)
        ->and($reserva->licenciaturasAdicionales()->value('licenciatura_id'))->toBe($this->derecho->id);
});

test('the detail page and the printable Formato 10 list the programs and their groups', function () {
    $reserva = ($this->crear)(['licenciaturas_adicionales' => ($this->adicionales)()]);

    $this->actingAs($this->docente)->get(route('reservas.imprimir', $reserva))->assertOk()
        ->assertSee('Licenciaturas participantes:')
        ->assertSee('Industrial')
        ->assertSee('3A, 3B')
        ->assertSee('Derecho')
        ->assertSee('(invitada)')
        ->assertSee('Psicología');

    // Sin licenciaturas adicionales la fila no aparece.
    $sola = ($this->crear)();
    $this->actingAs($this->docente)->get(route('reservas.imprimir', $sola))->assertOk()
        ->assertDontSee('Licenciaturas participantes:');
});
