<?php

use App\Models\Licenciatura;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Siglas oficiales de las licenciaturas (folio institucional del Formato 10): solo
 * administración las registra; son opcionales, de 2 a 10 letras A-Z, únicas y se
 * normalizan (sin espacios a los lados, en mayúsculas) antes de validar.
 */
beforeEach(function () {
    $this->admin = User::factory()->rol('admin')->create();
});

test('an admin registers normalized acronyms when creating and editing', function () {
    $this->actingAs($this->admin)->post(route('admin.licenciaturas.store'), ['nombre' => 'Sistemas', 'siglas' => '  lsc '])->assertSessionHasNoErrors();
    $licenciatura = Licenciatura::firstOrFail();
    expect($licenciatura->siglas)->toBe('LSC');

    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Sistemas', 'siglas' => 'isc'])->assertSessionHasNoErrors();
    expect($licenciatura->refresh()->siglas)->toBe('ISC');
});

test('acronyms are optional: empty clears them and an absent field keeps them', function () {
    $this->actingAs($this->admin)->post(route('admin.licenciaturas.store'), ['nombre' => 'Derecho'])->assertSessionHasNoErrors();
    $licenciatura = Licenciatura::firstOrFail();
    expect($licenciatura->siglas)->toBeNull();

    $licenciatura->forceFill(['siglas' => 'LDP'])->save();
    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Derecho y Política'])->assertSessionHasNoErrors();
    expect($licenciatura->refresh()->siglas)->toBe('LDP');

    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Derecho y Política', 'siglas' => '   '])->assertSessionHasNoErrors();
    expect($licenciatura->refresh()->siglas)->toBeNull();
});

test('invalid acronyms are rejected', function (mixed $siglas) {
    $this->actingAs($this->admin)->post(route('admin.licenciaturas.store'), ['nombre' => 'Psicología', 'siglas' => $siglas])
        ->assertSessionHasErrors('siglas');

    expect(Licenciatura::count())->toBe(0);
})->with([
    'una letra' => ['L'],
    'once letras' => ['ABCDEFGHIJK'],
    'con espacio' => ['L SC'],
    'con número' => ['LSC1'],
    'con guion' => ['L-SC'],
    'con acento' => ['LPSÍ'],
    'con eñe' => ['LÑ'],
    'no es texto' => [['LSC']],
]);

test('two programs cannot share an acronym, even with different case', function () {
    Licenciatura::factory()->create(['nombre' => 'Sistemas', 'siglas' => 'LSC']);
    $otra = Licenciatura::factory()->create(['nombre' => 'Software']);

    $this->actingAs($this->admin)->post(route('admin.licenciaturas.store'), ['nombre' => 'Computación', 'siglas' => 'lsc'])
        ->assertSessionHasErrors(['siglas' => 'Otra licenciatura ya usa esas siglas.']);
    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $otra), ['nombre' => 'Software', 'siglas' => ' LSC'])
        ->assertSessionHasErrors(['siglas' => 'Otra licenciatura ya usa esas siglas.']);

    expect($otra->refresh()->siglas)->toBeNull();
});

test('keeping the same acronym when renaming is allowed', function () {
    $licenciatura = Licenciatura::factory()->create(['nombre' => 'Sistemas', 'siglas' => 'LSC']);

    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Sistemas Computacionales', 'siglas' => 'LSC'])
        ->assertSessionHasNoErrors();

    expect($licenciatura->refresh()->only(['nombre', 'siglas']))->toBe(['nombre' => 'Sistemas Computacionales', 'siglas' => 'LSC']);
});

test('only administration can register or change acronyms', function (string $rol) {
    $licenciatura = Licenciatura::factory()->create(['nombre' => 'Sistemas', 'siglas' => 'LSC']);
    $usuario = User::factory()->rol($rol)->create();
    $usuario->licenciaturas()->attach($licenciatura);

    $this->actingAs($usuario)->post(route('admin.licenciaturas.store'), ['nombre' => 'Nueva', 'siglas' => 'NVA'])->assertForbidden();
    $this->actingAs($usuario)->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Sistemas', 'siglas' => 'XYZ'])->assertForbidden();

    expect($licenciatura->refresh()->siglas)->toBe('LSC')
        ->and(Licenciatura::count())->toBe(1);
})->with(['coordinador', 'docente']);

test('the catalog lists each program acronym', function () {
    Licenciatura::factory()->create(['nombre' => 'A Sistemas', 'siglas' => 'LSC']);
    Licenciatura::factory()->create(['nombre' => 'B Psicología']);

    $this->actingAs($this->admin)->get(route('admin.licenciaturas.index'))
        ->assertInertia(fn (Assert $p) => $p
            ->where('licenciaturas.0.siglas', 'LSC')
            ->where('licenciaturas.1.siglas', null));
});
