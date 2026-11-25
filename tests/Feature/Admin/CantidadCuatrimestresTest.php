<?php

use App\Models\Actividad;
use App\Models\Asignatura;
use App\Models\Cuatrimestre;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Cantidad de cuatrimestres por licenciatura (2026_11_24_000003): solo el superadministrador
 * la configura; no se puede reducir por debajo de un cuatrimestre que ya tenga datos.
 */
beforeEach(function () {
    $this->superadmin = User::factory()->rol('superadmin')->create();
    $this->admin = User::factory()->rol('admin')->create();
    $this->numero = fn (int $n): int => (int) Cuatrimestre::query()->where('numero', $n)->value('id');
});

test('the superadmin creates programs with 9, 11 and 12 terms and the catalog grows as needed', function (int $cantidad) {
    $this->actingAs($this->superadmin)->post(route('admin.licenciaturas.store'), [
        'nombre' => "Licenciatura de {$cantidad}", 'siglas' => 'L'.str_repeat('X', min($cantidad - 7, 5)), 'cantidad_cuatrimestres' => (string) $cantidad,
    ])->assertSessionHasNoErrors();

    $licenciatura = Licenciatura::query()->where('nombre', "Licenciatura de {$cantidad}")->firstOrFail();

    expect($licenciatura->cantidad_cuatrimestres)->toBe($cantidad)
        ->and(Cuatrimestre::query()->where('numero', '<=', $cantidad)->count())->toBe($cantidad)
        // Los nueve originales no cambian.
        ->and(Cuatrimestre::query()->where('numero', 9)->value('nombre'))->toBe('Noveno cuatrimestre');
})->with([9, 11, 12]);

test('new terms get proper names and existing rows are not duplicated', function () {
    $this->actingAs($this->superadmin)->post(route('admin.licenciaturas.store'), ['nombre' => 'Sistemas', 'cantidad_cuatrimestres' => 12]);
    $this->actingAs($this->superadmin)->post(route('admin.licenciaturas.store'), ['nombre' => 'Diseño', 'cantidad_cuatrimestres' => 11]);

    expect(Cuatrimestre::query()->orderBy('numero')->pluck('nombre', 'numero')->only([10, 11, 12])->all())
        ->toBe([10 => 'Décimo cuatrimestre', 11 => 'Decimoprimer cuatrimestre', 12 => 'Decimosegundo cuatrimestre'])
        ->and(Cuatrimestre::query()->count())->toBe(12);
});

test('a program without the field keeps the compatible default of 9', function () {
    $this->actingAs($this->admin)->post(route('admin.licenciaturas.store'), ['nombre' => 'Derecho'])->assertSessionHasNoErrors();

    expect(Licenciatura::query()->where('nombre', 'Derecho')->value('cantidad_cuatrimestres'))->toBe(9)
        ->and(Licenciatura::factory()->create()->refresh()->cantidad_cuatrimestres)->toBe(9);
});

test('only positive integers within the documented limit are accepted', function (mixed $valor) {
    $licenciatura = Licenciatura::factory()->create();

    $this->actingAs($this->superadmin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => $licenciatura->nombre, 'cantidad_cuatrimestres' => $valor])
        ->assertSessionHasErrors('cantidad_cuatrimestres');

    expect($licenciatura->refresh()->cantidad_cuatrimestres)->toBe(9);
})->with([0, -3, 21, 2.5, 'doce', '', null]);

test('the superadmin raises the number of terms', function () {
    $licenciatura = Licenciatura::factory()->create(['siglas' => 'LSC']);

    $this->actingAs($this->superadmin)->put(route('admin.licenciaturas.update', $licenciatura), [
        'nombre' => $licenciatura->nombre, 'siglas' => 'LSC', 'cantidad_cuatrimestres' => 11,
    ])->assertSessionHasNoErrors();

    expect($licenciatura->refresh()->cantidad_cuatrimestres)->toBe(11)
        ->and($licenciatura->siglas)->toBe('LSC')
        ->and($licenciatura->estatus)->toBe('activa');
});

test('lowering below a term that already has data is rejected and nothing changes', function () {
    $licenciatura = Licenciatura::factory()->create(['nombre' => 'Sistemas', 'cantidad_cuatrimestres' => 11]);
    Cuatrimestre::asegurarHasta(11);
    $docente = User::factory()->rol('docente')->create();
    $docente->licenciaturas()->attach($licenciatura->id);
    $reserva = Reserva::factory()->create([
        'licenciatura_id' => $licenciatura->id, 'usuario_creador_id' => $docente->id,
        'cuatrimestres' => [['id' => ($this->numero)(10), 'grupos' => ['A']]],
    ]);

    $this->actingAs($this->superadmin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Sistemas Computacionales', 'cantidad_cuatrimestres' => 9])
        ->assertSessionHasErrors(['cantidad_cuatrimestres' => 'No puedes reducirla a 9: el décimo cuatrimestre ya tiene grupos o reservaciones registrados (la reserva '.($reserva->refresh()->folio ?? Reserva::folioPara($reserva->id)).'). La cantidad mínima es 10.']);

    // Se conservan los datos anteriores (ni el nombre cambia) y la reserva sigue igual.
    expect($licenciatura->refresh()->cantidad_cuatrimestres)->toBe(11)
        ->and($licenciatura->nombre)->toBe('Sistemas')
        ->and($reserva->refresh()->cuatrimestres)->toBe([['id' => ($this->numero)(10), 'grupos' => ['A']]]);

    // Hasta 10 sí se puede.
    $this->actingAs($this->superadmin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Sistemas', 'cantidad_cuatrimestres' => 10])
        ->assertSessionHasNoErrors();
    expect($licenciatura->refresh()->cantidad_cuatrimestres)->toBe(10);
});

test('participating programs and catalog subjects also protect their terms', function () {
    $licenciatura = Licenciatura::factory()->create(['cantidad_cuatrimestres' => 12]);
    Cuatrimestre::asegurarHasta(12);
    $reserva = Reserva::factory()->create();
    $reserva->licenciaturasAdicionales()->create(['licenciatura_id' => $licenciatura->id, 'tipo' => 'invitada', 'cuatrimestres' => [['id' => ($this->numero)(11), 'grupos' => []]], 'orden' => 0]);

    $this->actingAs($this->superadmin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => $licenciatura->nombre, 'cantidad_cuatrimestres' => 10])
        ->assertSessionHasErrors('cantidad_cuatrimestres');

    Asignatura::query()->create(['nombre' => 'Tesis II', 'licenciatura_id' => $licenciatura->id, 'cuatrimestre_id' => ($this->numero)(12), 'estatus' => 'activa']);
    $this->actingAs($this->superadmin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => $licenciatura->nombre, 'cantidad_cuatrimestres' => 11])
        ->assertSessionHasErrors(['cantidad_cuatrimestres' => 'No puedes reducirla a 11: el decimosegundo cuatrimestre ya tiene grupos o reservaciones registrados (la asignatura Tesis II). La cantidad mínima es 12.']);

    expect($licenciatura->refresh()->cantidad_cuatrimestres)->toBe(12);
});

test('lowering is allowed when no data uses the higher terms', function () {
    $licenciatura = Licenciatura::factory()->create(['cantidad_cuatrimestres' => 12]);

    $this->actingAs($this->superadmin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => $licenciatura->nombre, 'cantidad_cuatrimestres' => 9])
        ->assertSessionHasNoErrors();

    expect($licenciatura->refresh()->cantidad_cuatrimestres)->toBe(9);
});

test('admin keeps managing programs but cannot set the number of terms, not even by HTTP', function () {
    $licenciatura = Licenciatura::factory()->create(['nombre' => 'Derecho', 'cantidad_cuatrimestres' => 9]);

    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Derecho Corporativo', 'cantidad_cuatrimestres' => 12])
        ->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.licenciaturas.store'), ['nombre' => 'Arquitectura', 'cantidad_cuatrimestres' => 12])
        ->assertForbidden();

    expect($licenciatura->refresh()->cantidad_cuatrimestres)->toBe(9)
        ->and($licenciatura->nombre)->toBe('Derecho')
        ->and(Licenciatura::query()->where('nombre', 'Arquitectura')->exists())->toBeFalse();

    // Lo demás del módulo sigue igual para el directivo.
    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Derecho Corporativo', 'siglas' => 'LDC'])
        ->assertSessionHasNoErrors();
    expect($licenciatura->refresh()->nombre)->toBe('Derecho Corporativo')->and($licenciatura->cantidad_cuatrimestres)->toBe(9);
});

test('coordinators and teachers cannot change the number of terms', function (string $rol) {
    $licenciatura = Licenciatura::factory()->create(['cantidad_cuatrimestres' => 9]);

    $this->actingAs(User::factory()->rol($rol)->create())
        ->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => $licenciatura->nombre, 'cantidad_cuatrimestres' => 12])
        ->assertForbidden();

    expect($licenciatura->refresh()->cantidad_cuatrimestres)->toBe(9);
})->with(['coordinador', 'docente']);

test('the list shows the number of terms and only the superadmin may edit it', function () {
    Licenciatura::factory()->create(['nombre' => 'Sistemas', 'cantidad_cuatrimestres' => 11]);

    $this->actingAs($this->superadmin)->get(route('admin.licenciaturas.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('licenciaturas.0.cantidad_cuatrimestres', 11)
            ->where('puedeConfigurarCuatrimestres', true)
            ->where('maxCuatrimestres', Licenciatura::MAX_CUATRIMESTRES));

    $this->actingAs($this->admin)->get(route('admin.licenciaturas.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('puedeConfigurarCuatrimestres', false));
});

test('changing the number of terms does not touch the institutional folio', function () {
    $licenciatura = Licenciatura::factory()->create(['siglas' => 'LSC']);
    $docente = User::factory()->rol('docente')->create();
    $docente->licenciaturas()->attach($licenciatura->id);
    $this->actingAs($docente)->post(route('reservas.store'), [
        'licenciatura_id' => $licenciatura->id, 'actividad_id' => Actividad::factory()->create()->id,
        'nombre_actividad' => 'Foro', 'objetivo' => 'Difundir',
    ])->assertSessionHasNoErrors();
    $folio = Reserva::query()->value('folio_institucional');

    $this->actingAs($this->superadmin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => $licenciatura->nombre, 'siglas' => 'LSC', 'cantidad_cuatrimestres' => 11]);

    expect(Reserva::query()->value('folio_institucional'))->toBe($folio)->toStartWith('LSC-');
});
