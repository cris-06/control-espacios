<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function verComo(string $rol): void
{
    test()->actingAs(User::factory()->rol($rol)->create());
}

test('guests cannot open the interface modules', function (string $ruta) {
    $this->get(route($ruta))->assertRedirect(route('login'));
})->with(['disponibilidad.index', 'solicitudes.index', 'reportes.index']);

test('the interface only has a light theme', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get(route('login'))
        ->assertDontSee('class="dark"', false)
        ->assertDontSee('prefers-color-scheme', false);

    $this->actingAs(User::factory()->create())->get('settings/appearance')->assertNotFound();
});

test('every role sees its own dashboard', function (string $rol, string $indicador) {
    verComo($rol);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $pagina) => $pagina
        ->component('Dashboard')
        ->where('auth.rol.nombre', $rol)
        ->where('indicadores.0.titulo', $indicador));
})->with([
    ['superadmin', 'Por autorizar'],
    ['admin', 'Por autorizar'],
    ['coordinador', 'Por revisar'],
    ['docente', 'Borradores'],
]);

// Las notificaciones reales (tabla `notificaciones`) se prueban en Reservas/HistorialNotificacionesTest.

test('the side menu starts expanded until the user collapses it', function () {
    verComo('admin');

    $this->get(route('dashboard'))->assertInertia(fn (Assert $pagina) => $pagina->where('sidebarOpen', true));

    $this->withUnencryptedCookie('sidebar_state', 'false')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina->where('sidebarOpen', false));
});

// La visibilidad por rol y licenciatura (solicitudes, reportes, disponibilidad y panel)
// se prueba con datos reales en Reservas/ReservaHttpTest y Reservas/RestriccionesLicenciaturaTest.

test('teachers cannot open reports', function () {
    verComo('docente');

    $this->get(route('reportes.index'))->assertForbidden();
});

test('reports reject a period that ends before it starts', function () {
    verComo('admin');

    $this->get(route('reportes.index', ['fecha_inicio' => '2026-11-10', 'fecha_fin' => '2026-11-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('errores.fecha_fin', 'La fecha de fin no puede ser anterior a la de inicio.')
            ->where('resultados', []));
});

test('availability rejects a schedule that ends before it starts', function () {
    verComo('admin');

    $this->get(route('disponibilidad.index', ['hora_inicio' => '12:00', 'hora_fin' => '10:00']))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('errores.hora_fin', 'La hora de fin debe ser posterior a la de inicio.')
            ->where('espacios', []));
});
