<?php

use App\Http\Middleware\ConservarMensajesDeSesion;
use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

/**
 * La consulta de disponibilidad en vivo (JSON, en segundo plano) no debe llevarse ni pisar los
 * errores de validación de un guardado, ni restaurar una sesión cerrada, ni sobrescribir la
 * autenticación, ni devolver mensajes ya mostrados, aunque se ejecute a la vez que otras peticiones.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lic = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->lic->id);
    $this->espacio = Espacio::factory()->create(['capacidad' => 30, 'minimo_alumnos' => 5, 'maximo_alumnos' => 30]);
    $this->actividad = Actividad::factory()->create();

    $this->invalida = [
        'licenciatura_id' => $this->lic->id, 'actividad_id' => $this->actividad->id,
        'nombre_actividad' => '', 'objetivo' => '',
        'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => null]],
    ];
    $this->verificar = fn () => $this->getJson(route('disponibilidad.verificar', [
        'espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
    ]))->assertOk();

    // «Recarga del formulario»: devuelve los errores que la sesión entrega a esa petición.
    Route::middleware(['web', 'auth'])->get('/_prueba/recarga', fn () => response()->json(
        session('errors')?->getBag('default')->toArray() ?? [],
    ));
    $this->recarga = fn () => $this->getJson('/_prueba/recarga')->assertOk();

    // --- Peticiones simultáneas con sesiones reales que comparten el almacenamiento ---------
    $this->almacen = new ArraySessionHandler(120);
    $this->id = Str::random(40);
    $this->sesion = fn (): Store => tap(new Store('sesion_prueba', $this->almacen, $this->id, 'json'))->start();
    $this->llaveAuth = 'login_web_'.sha1('prueba');
    $this->errores = (new ViewErrorBag)->put('default', new MessageBag(['nombre_actividad' => ['Escribe el nombre de la actividad.']]));

    // Estado inicial guardado: usuario autenticado.
    $inicial = ($this->sesion)();
    $inicial->put($this->llaveAuth, 7);
    $inicial->save();

    /**
     * Ejecuta la consulta de disponibilidad sobre su propia copia de la sesión (como StartSession:
     * la carga al empezar y la guarda al terminar) mientras `$otra` se ejecuta a la vez.
     */
    $this->consultaSimultanea = function (Closure $otra, bool $conMiddleware = true): void {
        $consulta = ($this->sesion)();
        $request = Request::create('/disponibilidad/verificar');
        $request->setLaravelSession($consulta);
        $atender = function () use ($otra) {
            $otra();

            return response()->json(['ok' => true]);
        };

        $conMiddleware ? (new ConservarMensajesDeSesion)->handle($request, $atender) : $atender();
        $consulta->save();
    };
});

test('the availability route uses the middleware that protects the session', function () {
    expect(Route::getRoutes()->getByName('disponibilidad.verificar')->gatherMiddleware())
        ->toContain(ConservarMensajesDeSesion::class);
});

test('validation errors survive availability checks that arrive between the save and the reload', function () {
    $this->actingAs($this->docente)->from(route('reservas.create'))
        ->post(route('reservas.store'), $this->invalida)
        ->assertRedirect(route('reservas.create'));
    // Sin assertSessionHasErrors aquí: deja los errores en memoria de una forma que la petición
    // simulada siguiente ya no puede volver a cargar (solo pasa en las pruebas). Los errores se
    // comprueban en la recarga.

    ($this->verificar)();
    ($this->verificar)();

    ($this->recarga)()->assertExactJson([
        'nombre_actividad' => ['Escribe el nombre de la actividad.'],
        'objetivo' => ['Escribe el objetivo de la actividad.'],
        'detalles.0.cantidad_alumnos' => ['Indica la cantidad de alumnos.'],
    ]);
    expect(Reserva::query()->count())->toBe(0);
});

test('the errors are delivered only once: later reloads no longer have them', function () {
    $this->actingAs($this->docente)->from(route('reservas.create'))
        ->post(route('reservas.store'), $this->invalida)->assertRedirect(route('reservas.create'));
    ($this->verificar)();
    ($this->recarga)()->assertJsonPath('nombre_actividad', ['Escribe el nombre de la actividad.']);

    ($this->verificar)();
    ($this->recarga)()->assertExactJson([]);
});

test('a successful save is not affected and leaves no errors behind', function () {
    $valida = [...$this->invalida, 'nombre_actividad' => 'Jornada', 'objetivo' => 'Objetivo',
        'detalles' => [[...$this->invalida['detalles'][0], 'cantidad_alumnos' => 20]]];

    $this->actingAs($this->docente)->post(route('reservas.store'), $valida)->assertSessionHasNoErrors();
    ($this->verificar)();

    ($this->recarga)()->assertExactJson([]);
    expect(Reserva::query()->count())->toBe(1);
});

// --- Peticiones simultáneas ---------------------------------------------------------------

test('a check that runs while a save writes errors does not overwrite them', function (bool $conMiddleware) {
    ($this->consultaSimultanea)(function () {
        $guardado = ($this->sesion)();
        $guardado->flash('errors', $this->errores);
        $guardado->save();
    }, $conMiddleware);

    $recarga = ($this->sesion)();
    // Sin el middleware, la consulta guarda su copia antigua y los errores se pierden.
    expect($recarga->get('errors')?->getBag('default')->first('nombre_actividad'))
        ->toBe($conMiddleware ? 'Escribe el nombre de la actividad.' : null)
        ->and($recarga->get($this->llaveAuth))->toBe(7);
})->with(['con ConservarMensajesDeSesion' => [true], 'sin el middleware' => [false]]);

test('a check that runs while the user logs out does not restore the closed session', function (bool $conMiddleware) {
    ($this->consultaSimultanea)(function () {
        $logout = ($this->sesion)();
        $logout->invalidate();
        $logout->save();
    }, $conMiddleware);

    // Sin el middleware, la consulta vuelve a escribir la sesión cerrada (con el usuario).
    expect($this->almacen->read($this->id) === '')->toBe($conMiddleware);
})->with(['con ConservarMensajesDeSesion' => [true], 'sin el middleware' => [false]]);

test('a check does not bring back authentication data removed by another request', function () {
    ($this->consultaSimultanea)(function () {
        $otra = ($this->sesion)();
        $otra->forget($this->llaveAuth);
        $otra->save();
    });

    expect(($this->sesion)()->has($this->llaveAuth))->toBeFalse();
});

test('a check does not bring back messages that the reload already showed', function () {
    // Errores pendientes de mostrar.
    $guardado = ($this->sesion)();
    $guardado->flash('errors', $this->errores);
    $guardado->save();

    // La consulta empieza con los errores pendientes; mientras tanto, la recarga los muestra.
    ($this->consultaSimultanea)(function () {
        $recarga = ($this->sesion)();
        expect($recarga->has('errors'))->toBeTrue();
        $recarga->save();
    });

    expect(($this->sesion)()->has('errors'))->toBeFalse();
});
