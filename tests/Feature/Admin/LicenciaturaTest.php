<?php

use App\Models\Licenciatura;
use App\Models\User;

test('only administration manages degree programs', function (string $rol, int $estado) {
    $this->actingAs(User::factory()->rol($rol)->create())
        ->get(route('admin.licenciaturas.index'))
        ->assertStatus($estado);
})->with([
    ['admin', 200],
    ['superadmin', 200],
    ['coordinador', 403],
    ['docente', 403],
]);

test('coordinators cannot create degree programs posting directly', function () {
    $this->actingAs(User::factory()->rol('coordinador')->create())
        ->post(route('admin.licenciaturas.store'), ['nombre' => 'Derecho'])
        ->assertForbidden();

    expect(Licenciatura::count())->toBe(0);
});

test('an admin creates, renames and deactivates a degree program', function () {
    $this->actingAs(User::factory()->rol('admin')->create());

    $this->post(route('admin.licenciaturas.store'), ['nombre' => '  Derecho '])->assertSessionHasNoErrors();
    $licenciatura = Licenciatura::firstOrFail();
    expect($licenciatura->nombre)->toBe('Derecho')->and($licenciatura->estatus)->toBe('activa');

    $this->put(route('admin.licenciaturas.update', $licenciatura), ['nombre' => 'Derecho Corporativo'])->assertSessionHasNoErrors();
    expect($licenciatura->refresh()->nombre)->toBe('Derecho Corporativo');

    $this->patch(route('admin.licenciaturas.estatus', $licenciatura));
    expect($licenciatura->refresh()->estatus)->toBe('inactiva');
});

test('degree program names are unique', function () {
    Licenciatura::factory()->create(['nombre' => 'Psicología']);

    $this->actingAs(User::factory()->rol('admin')->create())
        ->post(route('admin.licenciaturas.store'), ['nombre' => 'Psicología'])
        ->assertSessionHasErrors(['nombre' => 'Ya existe una licenciatura con ese nombre.']);
});
