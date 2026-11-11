<?php

use App\Models\Actividad;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->workflow = app(W::class);
    $this->licenciatura = Licenciatura::factory()->create();

    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);

    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->otroCoordinador = User::factory()->rol('coordinador')->create();
    $this->otroCoordinador->licenciaturas()->attach($this->licenciatura);
    $this->coordinadorInactivo = User::factory()->rol('coordinador')->inactivo()->create();
    $this->coordinadorInactivo->licenciaturas()->attach($this->licenciatura);
    $this->coordinadorAjeno = User::factory()->rol('coordinador')->create();
    $this->coordinadorAjeno->licenciaturas()->attach(Licenciatura::factory()->create());

    $this->admin = User::factory()->rol('admin')->create();
    $this->superadmin = User::factory()->rol('superadmin')->create();

    $this->reserva = fn (int $estado = E::BORRADOR, ?User $creador = null) => Reserva::factory()
        ->enEstado($estado)
        ->conDetalle()
        ->create([
            'usuario_creador_id' => ($creador ?? $this->docente)->id,
            'licenciatura_id' => $this->licenciatura->id,
        ]);
});

function destinatarios(): array
{
    return Notificacion::query()->orderBy('usuario_id')->pluck('usuario_id')->all();
}

// --- Notificaciones por transición ---

test('every active coordinator of the program is notified when a request arrives', function () {
    $reserva = ($this->reserva)();

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect(destinatarios())->toBe([$this->coordinador->id, $this->otroCoordinador->id]);

    $notificacion = Notificacion::firstOrFail();
    expect($notificacion->reserva_id)->toBe($reserva->id)
        ->and($notificacion->titulo)->toBe('Nueva solicitud por revisar')
        ->and($notificacion->leida)->toBeFalse()
        ->and($notificacion->mensaje)->toContain($reserva->folio ?? (string) $reserva->id);
});

test('administration is notified when the coordinator approves', function () {
    $reserva = ($this->reserva)(E::PENDIENTE_COORDINADOR);

    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);

    expect(destinatarios())->toBe([$this->admin->id, $this->superadmin->id])
        ->and(Notificacion::first()->mensaje)->toContain('aprobó en coordinación');
});

test('administration is notified when a coordinator sends their own reservation', function () {
    $reserva = ($this->reserva)(creador: $this->coordinador);

    $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR);

    expect(destinatarios())->toBe([$this->admin->id, $this->superadmin->id])
        ->and(Notificacion::first()->mensaje)->toContain('envió su reserva');
});

test('the creator is notified of corrections, authorizations and denials', function (int $estado, string $actor, string $accion, string $titulo, string $tipo) {
    $reserva = ($this->reserva)($estado);

    $this->workflow->ejecutar($reserva, $this->{$actor}, $accion, 'Motivo de la decisión');

    expect(destinatarios())->toBe([$this->docente->id]);

    $notificacion = Notificacion::firstOrFail();
    expect($notificacion->titulo)->toBe($titulo)
        ->and($notificacion->tipo)->toBe($tipo)
        ->and($notificacion->mensaje)->toContain('Motivo de la decisión');
})->with([
    'corrección del coordinador' => [E::PENDIENTE_COORDINADOR, 'coordinador', W::SOLICITAR_CORRECCION, 'Tu reserva requiere corrección', 'warning'],
    'corrección de administración' => [E::PENDIENTE_ADMIN, 'admin', W::SOLICITAR_CORRECCION, 'Tu reserva requiere corrección', 'warning'],
    'autorización' => [E::PENDIENTE_ADMIN, 'admin', W::AUTORIZAR, 'Reserva autorizada', 'success'],
    'denegación' => [E::PENDIENTE_ADMIN, 'admin', W::DENEGAR, 'Reserva denegada', 'error'],
]);

test('a failed transition sends no notification', function () {
    $reserva = ($this->reserva)(E::PENDIENTE_ADMIN);

    try {
        $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR); // sin observación
    } catch (Throwable) {
    }

    expect(Notificacion::count())->toBe(0);
});

// --- Contador y marcar como leída ---

test('the unread counter and recent notifications are shared on every page', function () {
    $this->workflow->ejecutar(($this->reserva)(), $this->docente, W::ENVIAR);
    $this->workflow->ejecutar(($this->reserva)(), $this->docente, W::ENVIAR);

    $this->actingAs($this->coordinador)->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('notificaciones.noLeidas', 2)
            ->has('notificaciones.recientes', 2)
            ->where('porAtender', 2));

    $this->actingAs($this->coordinadorAjeno)->get(route('dashboard'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('notificaciones.noLeidas', 0)
            ->where('porAtender', 0));
});

test('opening a notification marks it as read and goes to its reservation', function () {
    $reserva = ($this->reserva)();
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $notificacion = Notificacion::where('usuario_id', $this->coordinador->id)->firstOrFail();

    $this->actingAs($this->coordinador)
        ->post(route('notificaciones.leer', $notificacion->id))
        ->assertRedirect(route('reservas.show', $reserva));

    expect($notificacion->refresh()->leida)->toBeTrue();
});

test('nobody can read someone else\'s notification', function () {
    $this->workflow->ejecutar(($this->reserva)(), $this->docente, W::ENVIAR);
    $ajena = Notificacion::where('usuario_id', $this->coordinador->id)->firstOrFail();

    $this->actingAs($this->otroCoordinador)
        ->post(route('notificaciones.leer', $ajena->id))
        ->assertNotFound();

    expect($ajena->refresh()->leida)->toBeFalse();
});

test('marking all as read only touches your own notifications', function () {
    $this->workflow->ejecutar(($this->reserva)(), $this->docente, W::ENVIAR);

    $this->actingAs($this->coordinador)->post(route('notificaciones.leer-todas'))->assertRedirect();

    expect(Notificacion::where('usuario_id', $this->coordinador->id)->noLeidas()->count())->toBe(0)
        ->and(Notificacion::where('usuario_id', $this->otroCoordinador->id)->noLeidas()->count())->toBe(1);
});

test('the notifications page lists only your own', function () {
    $this->workflow->ejecutar(($this->reserva)(), $this->docente, W::ENVIAR);

    $this->actingAs($this->coordinador)->get(route('notificaciones.index'))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('notificaciones/Index')
            ->has('notificaciones.data', 1));

    $this->actingAs($this->docente)->get(route('notificaciones.index'))
        ->assertInertia(fn (Assert $pagina) => $pagina->has('notificaciones.data', 0));
});

// --- Historial ---

test('every transition leaves a valid history entry and the timeline shows them', function () {
    $reserva = ($this->reserva)();

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Ajusta el horario');
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR, 'Listo');
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    $this->workflow->ejecutar($reserva, $this->admin, W::DENEGAR, 'Sin cupo');

    $this->actingAs($this->docente)->get(route('reservas.show', $reserva))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('reserva.historial', fn ($historial) => collect($historial)->pluck('accion')->all() === [
                HistorialReserva::ENVIADA_COORDINADOR,
                HistorialReserva::CORRECCION_SOLICITADA,
                HistorialReserva::ENVIADA_COORDINADOR,
                HistorialReserva::APROBADA_COORDINADOR,
                HistorialReserva::DENEGADA,
            ])
            ->where('reserva.historial.1.comentario', 'Ajusta el horario')
            ->where('reserva.historial.4.usuario', $this->admin->name));
});

test('a coordinator editing a teacher\'s reservation is recorded with a summary', function () {
    $reserva = ($this->reserva)(E::PENDIENTE_COORDINADOR);
    $reserva->load('detalles');
    $detalle = $reserva->detalles->first();

    $this->actingAs($this->coordinador)->put(route('reservas.update', $reserva), [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => $reserva->actividad_id,
        'nombre_actividad' => 'Nombre corregido por coordinación',
        'objetivo' => $reserva->objetivo,
        'detalles' => [[
            'id' => $detalle->id,
            'espacio_id' => $detalle->espacio_id,
            'fecha' => Date::today()->addDays(30)->toDateString(),
            'hora_inicio' => '13:00',
            'hora_fin' => '14:00',
            'cantidad_alumnos' => 15,
        ]],
    ])->assertSessionHasNoErrors();

    $registro = $reserva->historial()->latest('id')->firstOrFail();
    expect($registro->accion)->toBe(HistorialReserva::EDITADA_COORDINADOR)
        ->and($registro->usuario_id)->toBe($this->coordinador->id)
        ->and($registro->comentario)->toBe('Cambió: nombre de la actividad, espacios y horarios.');
});

test('the creator editing their own draft is not recorded as a coordinator edit', function () {
    $reserva = ($this->reserva)();

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => Actividad::factory()->create()->id,
        'nombre_actividad' => 'Otro nombre',
        'detalles' => [],
    ])->assertSessionHasNoErrors();

    expect($reserva->historial()->where('accion', HistorialReserva::EDITADA_COORDINADOR)->exists())->toBeFalse();
});

test('saving without changes does not add a coordinator edit entry', function () {
    $reserva = ($this->reserva)(E::PENDIENTE_COORDINADOR);
    $detalle = $reserva->detalles()->first();

    $this->actingAs($this->coordinador)->put(route('reservas.update', $reserva), [
        'licenciatura_id' => $reserva->licenciatura_id,
        'actividad_id' => $reserva->actividad_id,
        'nombre_actividad' => $reserva->nombre_actividad,
        'objetivo' => $reserva->objetivo,
        'detalles' => [[
            'id' => $detalle->id,
            'espacio_id' => $detalle->espacio_id,
            'fecha' => $detalle->fecha->toDateString(),
            'hora_inicio' => substr($detalle->hora_inicio, 0, 5),
            'hora_fin' => substr($detalle->hora_fin, 0, 5),
            'cantidad_alumnos' => $detalle->cantidad_alumnos,
        ]],
    ])->assertSessionHasNoErrors();

    expect($reserva->historial()->where('accion', HistorialReserva::EDITADA_COORDINADOR)->exists())->toBeFalse();
});
