<?php

use App\Models\User;
use App\Support\DatosEjemplo;
use Inertia\Testing\AssertableInertia as Assert;

function verComo(string $rol): void
{
    test()->actingAs(User::factory()->create())
        ->withSession(['rol_demo' => $rol]);
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
        ->where('demo.rol', $rol)
        ->where('indicadores.0.titulo', $indicador));
})->with([
    ['superadmin', 'Por autorizar'],
    ['admin', 'Por autorizar'],
    ['coordinador', 'Por revisar'],
    ['docente', 'Borradores'],
]);

test('notifications list what each role has to act on', function (string $rol, array $estados) {
    verComo($rol);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $pagina) => $pagina
        ->where('demo.notificaciones', fn ($notificaciones) => $notificaciones->isNotEmpty()
            && $notificaciones->every(fn (array $notificacion) => in_array($notificacion['estado_id'], $estados, true))));
})->with([
    ['admin', [DatosEjemplo::PENDIENTE_ADMIN]],
    ['coordinador', [DatosEjemplo::PENDIENTE_COORDINADOR]],
    ['docente', [DatosEjemplo::BORRADOR, DatosEjemplo::CORRECCION]],
]);

test('the side menu starts expanded until the user collapses it', function () {
    verComo('admin');

    $this->get(route('dashboard'))->assertInertia(fn (Assert $pagina) => $pagina->where('sidebarOpen', true));

    $this->withUnencryptedCookie('sidebar_state', 'false')
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina->where('sidebarOpen', false));
});

test('teachers only see their own requests', function () {
    verComo('docente');

    $this->get(route('solicitudes.index'))->assertInertia(fn (Assert $pagina) => $pagina
        ->where('solicitudes', fn ($solicitudes) => $solicitudes->isNotEmpty()
            && $solicitudes->every(fn (array $solicitud) => $solicitud['usuario_creador_id'] === 3)));
});

test('coordinators do not see drafts or other degree programs', function () {
    verComo('coordinador');

    $this->get(route('solicitudes.index'))->assertInertia(fn (Assert $pagina) => $pagina
        ->where('solicitudes', fn ($solicitudes) => $solicitudes->isNotEmpty()
            && $solicitudes->every(fn (array $solicitud) => in_array($solicitud['licenciatura_id'], [1, 2], true)
                && $solicitud['estado_id'] !== DatosEjemplo::BORRADOR)));
});

test('teachers cannot open reports', function () {
    verComo('docente');

    $this->get(route('reportes.index'))->assertForbidden();
});

test('coordinators cannot report on another degree program', function () {
    verComo('coordinador');

    $this->get(route('reportes.index', ['licenciatura_id' => 3]))->assertInertia(fn (Assert $pagina) => $pagina
        ->where('errores.licenciatura_id', 'La licenciatura seleccionada no está disponible.')
        ->where('resultados', []));
});

test('reports filter by degree program and add up by status', function () {
    verComo('admin');

    $this->get(route('reportes.index', ['licenciatura_id' => 4]))->assertInertia(fn (Assert $pagina) => $pagina
        ->has('resultados', 2)
        ->has('totalesEstado', 2)
        ->where('totalesEstado.0.nombre', 'Autorizada')
        ->where('totalesEstado.1.nombre', 'Denegada'));
});

test('reports reject a period that ends before it starts', function () {
    verComo('admin');

    $this->get(route('reportes.index', ['fecha_inicio' => '2026-11-10', 'fecha_fin' => '2026-11-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('errores.fecha_fin', 'La fecha de fin no puede ser anterior a la de inicio.')
            ->where('resultados', []));
});

test('availability tells apart occupied, pending, free and out of service spaces', function () {
    verComo('admin');

    $this->get(route('disponibilidad.index', ['hora_inicio' => '11:30', 'hora_fin' => '12:30']))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('espacios.0.situacion', 'ocupado')
            ->where('espacios.1.situacion', 'libre')
            ->where('espacios.2.situacion', 'pendiente')
            ->where('espacios.3.situacion', 'ocupado')
            ->where('espacios.4.situacion', 'fuera_de_servicio')
            ->where('espacios.5.situacion', 'fuera_de_servicio'));
});

test('a reservation ending exactly when the search starts is not a conflict', function () {
    verComo('admin');

    $this->get(route('disponibilidad.index', ['espacio_id' => 2, 'hora_inicio' => '10:00', 'hora_fin' => '11:00']))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->has('espacios', 1)
            ->where('espacios.0.situacion', 'libre'));
});

test('availability rejects a schedule that ends before it starts', function () {
    verComo('admin');

    $this->get(route('disponibilidad.index', ['hora_inicio' => '12:00', 'hora_fin' => '10:00']))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('errores.hora_fin', 'La hora de fin debe ser posterior a la de inicio.')
            ->where('espacios', []));
});

test('changing the demo role never lands on a page the new role cannot open', function () {
    verComo('admin');

    $this->from(route('reportes.index'))
        ->post(route('demo.rol'), ['rol' => 'docente'])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertOk();
});

test('the demo role can be changed and rejects unknown roles', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('demo.rol'), ['rol' => 'docente'])->assertSessionHas('rol_demo', 'docente');

    $this->from(route('dashboard'))
        ->post(route('demo.rol'), ['rol' => 'rector'])
        ->assertSessionHasErrors('rol');
});
