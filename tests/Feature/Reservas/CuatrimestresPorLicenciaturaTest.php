<?php

use App\Models\Actividad;
use App\Models\Cuatrimestre;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Formato 10: cada licenciatura (principal, participante o invitada) solo admite del primer
 * cuatrimestre a su cantidad configurada; Laravel rechaza cualquier cuatrimestre fuera de rango.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));
    Cuatrimestre::asegurarHasta(12);
    $this->c = fn (int $numero): int => (int) Cuatrimestre::query()->where('numero', $numero)->value('id');

    $this->diseno = Licenciatura::factory()->create(['nombre' => 'Diseño Publicitario', 'cantidad_cuatrimestres' => 9]);
    $this->sistemas = Licenciatura::factory()->create(['nombre' => 'Sistemas Computacionales', 'cantidad_cuatrimestres' => 11]);
    $this->otra = Licenciatura::factory()->create(['nombre' => 'Arquitectura', 'cantidad_cuatrimestres' => 12]);
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach([$this->sistemas->id, $this->diseno->id]);
    $this->actividad = Actividad::factory()->create();

    $this->datos = fn (array $extra = []): array => [
        'licenciatura_id' => $this->sistemas->id, 'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Hackatón', 'objetivo' => 'Resolver retos',
        ...$extra,
    ];
    $this->post = fn (array $extra) => $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)($extra));
});

test('the form receives the number of terms of every program and the extended catalog', function () {
    $this->actingAs($this->docente)->get(route('reservas.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('opciones.cuatrimestres', 12)
            ->where('opciones.cuatrimestres.11.nombre', 'Decimosegundo cuatrimestre')
            ->where("opciones.cuatrimestres_por_licenciatura.{$this->diseno->id}", 9)
            ->where("opciones.cuatrimestres_por_licenciatura.{$this->sistemas->id}", 11)
            ->where("opciones.cuatrimestres_por_licenciatura.{$this->otra->id}", 12));
});

test('principal, participating and invited programs keep their own terms and groups, and edit recovers them', function () {
    ($this->post)([
        'cuatrimestres' => [['id' => ($this->c)(11), 'grupos' => ['A', 'D']], ['id' => ($this->c)(1), 'grupos' => []]],
        'licenciaturas_adicionales' => [
            ['licenciatura_id' => $this->diseno->id, 'tipo' => 'participante', 'cuatrimestres' => [['id' => ($this->c)(9), 'grupos' => ['B']]]],
            ['licenciatura_id' => $this->otra->id, 'tipo' => 'invitada', 'cuatrimestres' => [['id' => ($this->c)(12), 'grupos' => ['F']]]],
        ],
    ])->assertSessionHasNoErrors();

    $reserva = Reserva::query()->firstOrFail();
    expect($reserva->cuatrimestres)->toBe([['id' => ($this->c)(1), 'grupos' => []], ['id' => ($this->c)(11), 'grupos' => ['A', 'D']]])
        ->and($reserva->licenciaturasAdicionales()->pluck('cuatrimestres')->all())->toBe([
            [['id' => ($this->c)(9), 'grupos' => ['B']]],
            [['id' => ($this->c)(12), 'grupos' => ['F']]],
        ]);

    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reserva.cuatrimestres', [['id' => ($this->c)(1), 'grupos' => []], ['id' => ($this->c)(11), 'grupos' => ['A', 'D']]])
            ->where('reserva.licenciaturas_adicionales.1.cuatrimestres', [['id' => ($this->c)(12), 'grupos' => ['F']]]));

    // El periodo impreso conserva los grupos personalizados (D) y los cuatrimestres altos.
    $this->actingAs($this->docente)->get(route('reservas.imprimir', $reserva))->assertOk()
        ->assertSee('11A, 11D')
        ->assertSee('12F');
});

test('a term beyond the configured number is rejected for each kind of program', function () {
    // Principal: Diseño tiene 9.
    ($this->post)(['licenciatura_id' => $this->diseno->id, 'cuatrimestres' => [['id' => ($this->c)(10), 'grupos' => ['A']]]])
        ->assertSessionHasErrors(['cuatrimestres' => 'Diseño Publicitario tiene 9 cuatrimestres: el décimo cuatrimestre no está disponible.']);

    // Principal: Sistemas tiene 11.
    ($this->post)(['cuatrimestres' => [['id' => ($this->c)(12), 'grupos' => []]]])
        ->assertSessionHasErrors(['cuatrimestres' => 'Sistemas Computacionales tiene 11 cuatrimestres: el decimosegundo cuatrimestre no está disponible.']);

    // Participante e invitada.
    ($this->post)(['licenciaturas_adicionales' => [
        ['licenciatura_id' => $this->diseno->id, 'tipo' => 'participante', 'cuatrimestres' => [['id' => ($this->c)(11), 'grupos' => []]]],
    ]])->assertSessionHasErrors(['licenciaturas_adicionales.0.cuatrimestres' => 'Diseño Publicitario tiene 9 cuatrimestres: el decimoprimer cuatrimestre no está disponible.']);

    $diezMas = Licenciatura::factory()->create(['nombre' => 'Enfermería', 'cantidad_cuatrimestres' => 10]);
    ($this->post)(['licenciaturas_adicionales' => [
        ['licenciatura_id' => $diezMas->id, 'tipo' => 'invitada', 'cuatrimestres' => [['id' => ($this->c)(11), 'grupos' => []]]],
    ]])->assertSessionHasErrors('licenciaturas_adicionales.0.cuatrimestres');

    // Un id que no existe en el catálogo sigue rechazándose como antes.
    ($this->post)(['cuatrimestres' => [['id' => 999, 'grupos' => []]]])->assertSessionHasErrors('cuatrimestres.0.id');

    expect(Reserva::query()->count())->toBe(0);
});

test('duplicated terms are rejected and repeated groups are stored once', function () {
    ($this->post)(['cuatrimestres' => [['id' => ($this->c)(2), 'grupos' => []], ['id' => ($this->c)(2), 'grupos' => ['A']]]])
        ->assertSessionHasErrors('cuatrimestres.0.id');
    expect(Reserva::query()->count())->toBe(0);

    // Un grupo repetido (petición manipulada) se guarda una sola vez, en la principal y en las adicionales.
    ($this->post)([
        'cuatrimestres' => [['id' => ($this->c)(2), 'grupos' => ['B', 'A', 'b']]],
        'licenciaturas_adicionales' => [
            ['licenciatura_id' => $this->otra->id, 'tipo' => 'invitada', 'cuatrimestres' => [['id' => ($this->c)(12), 'grupos' => ['C', 'C']]]],
        ],
    ])->assertSessionHasNoErrors();

    $reserva = Reserva::query()->firstOrFail();
    expect($reserva->cuatrimestres)->toBe([['id' => ($this->c)(2), 'grupos' => ['A', 'B']]])
        ->and($reserva->licenciaturasAdicionales()->value('cuatrimestres'))->toBe([['id' => ($this->c)(12), 'grupos' => ['C']]]);
});
