<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Ruta de prueba para ejercitar el middleware sin depender de módulos reales.
    Route::middleware(['web', 'auth', 'rol:admin,superadmin'])
        ->get('/_prueba/solo-admin', fn () => 'ok');
});

test('the rol middleware lets the listed roles through', function (string $rol) {
    $this->actingAs(User::factory()->rol($rol)->create())
        ->get('/_prueba/solo-admin')
        ->assertOk();
})->with(['admin', 'superadmin']);

test('the rol middleware answers 403 to other roles', function (string $rol) {
    $this->actingAs(User::factory()->rol($rol)->create())
        ->get('/_prueba/solo-admin')
        ->assertForbidden();
})->with(['coordinador', 'docente']);

test('reports are only for coordination and administration', function (string $rol, int $estado) {
    $this->actingAs(User::factory()->rol($rol)->create())
        ->get(route('reportes.index'))
        ->assertStatus($estado);
})->with([
    ['superadmin', 200],
    ['admin', 200],
    ['coordinador', 200],
    ['docente', 403],
]);

test('every role can open the general modules', function (string $rol, string $ruta) {
    $this->actingAs(User::factory()->rol($rol)->create())
        ->get(route($ruta))
        ->assertOk();
})->with(['superadmin', 'admin', 'coordinador', 'docente'])
    ->with(['dashboard', 'disponibilidad.index', 'solicitudes.index']);

test('the shared props carry the real role and permissions', function () {
    $this->actingAs(User::factory()->rol('coordinador')->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($pagina) => $pagina
            ->where('auth.rol.nombre', 'coordinador')
            ->where('auth.rol.nivel', 70)
            ->where('auth.permisos.verReportes', true)
            ->where('auth.permisos.administrar', false));
});

test('the demo role switcher no longer exists', function () {
    $this->actingAs(User::factory()->rol('docente')->create())
        ->post('/demo/rol', ['rol' => 'admin'])
        ->assertNotFound();
});

test('a user deactivated while logged in is logged out on the next request', function () {
    $user = User::factory()->rol('docente')->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->update(['estatus' => 'inactivo']);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    $this->assertGuest();
});

test('gates compare role levels', function () {
    $superadmin = User::factory()->rol('superadmin')->create();
    $admin = User::factory()->rol('admin')->create();
    $docente = User::factory()->rol('docente')->create();

    expect($admin->can('administrar'))->toBeTrue()
        ->and($docente->can('administrar'))->toBeFalse()
        ->and($admin->can('gestionar-usuario', $docente))->toBeTrue()
        ->and($admin->can('gestionar-usuario', $admin))->toBeTrue()
        ->and($admin->can('gestionar-usuario', $superadmin))->toBeFalse()
        ->and($superadmin->can('gestionar-usuario', $admin))->toBeTrue();
});
