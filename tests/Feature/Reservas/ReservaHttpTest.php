<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->otraLicenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->admin = User::factory()->rol('admin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create();
});

function datosReserva(array $cambios = []): array
{
    return [
        'licenciatura_id' => test()->licenciatura->id,
        'actividad_id' => test()->actividad->id,
        'nombre_actividad' => 'Práctica de redes',
        'objetivo' => 'Configurar routers',
        'detalles' => [[
            'espacio_id' => test()->espacio->id,
            'fecha' => Date::tomorrow()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_alumnos' => 20,
        ]],
        ...$cambios,
    ];
}

test('a teacher creates a draft with its details', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), datosReserva())
        ->assertSessionHasNoErrors();

    $reserva = Reserva::firstOrFail();
    expect($reserva->estado_id)->toBe(E::BORRADOR)
        ->and($reserva->usuario_creador_id)->toBe($this->docente->id)
        ->and($reserva->coordinador_id)->toBe($this->coordinador->id)
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and($reserva->detalles()->first()->hora_inicio)->toBe('10:00:00')
        ->and($reserva->historial()->value('accion'))->toBe(HistorialReserva::CREADA);
});

test('the state cannot be set when saving', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), datosReserva(['estado_id' => E::AUTORIZADA]));

    expect(Reserva::firstOrFail()->estado_id)->toBe(E::BORRADOR);
});

test('a teacher only registers reservations for their programs', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), datosReserva(['licenciatura_id' => $this->otraLicenciatura->id]))
        ->assertSessionHasErrors(['licenciatura_id' => 'Solo puedes registrar reservas de tus licenciaturas.']);
});

test('a draft can be saved without details', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), datosReserva(['detalles' => [], 'objetivo' => null]))
        ->assertSessionHasNoErrors();

    expect(Reserva::firstOrFail()->objetivo)->toBe('');
});

test('administration cannot create reservations', function () {
    $this->actingAs($this->admin)->get(route('reservas.create'))->assertForbidden();
    $this->actingAs($this->admin)->post(route('reservas.store'), datosReserva())->assertForbidden();
});

test('a teacher edits only in draft or correction', function () {
    $reserva = Reserva::factory()->conDetalle()->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), datosReserva(['nombre_actividad' => 'Nuevo nombre']))
        ->assertRedirect(route('reservas.show', $reserva));
    expect($reserva->refresh()->nombre_actividad)->toBe('Nuevo nombre');

    $reserva->forceFill(['estado_id' => E::PENDIENTE_COORDINADOR])->save();

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), datosReserva())->assertForbidden();
    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))->assertForbidden();
});

test('the coordinator completes a pending reservation and replaces its details', function () {
    $reserva = Reserva::factory()->conDetalle()->enEstado(E::PENDIENTE_COORDINADOR)
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $otroEspacio = Espacio::factory()->create();

    $this->actingAs($this->coordinador)
        ->put(route('reservas.update', $reserva), datosReserva(['detalles' => [
            ['espacio_id' => $otroEspacio->id, 'fecha' => Date::tomorrow()->toDateString(), 'hora_inicio' => '14:00', 'hora_fin' => '15:00', 'cantidad_alumnos' => 10],
        ]]))
        ->assertSessionHasNoErrors();

    expect($reserva->detalles()->count())->toBe(1)
        ->and($reserva->detalles()->value('espacio_id'))->toBe($otroEspacio->id);
});

test('the full flow over HTTP', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), datosReserva());
    $reserva = Reserva::firstOrFail();

    $this->actingAs($this->docente)->post(route('reservas.transicion', $reserva), ['accion' => 'enviar'])->assertSessionHasNoErrors();
    $this->actingAs($this->coordinador)->post(route('reservas.transicion', $reserva), ['accion' => 'aprobar'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('reservas.transicion', $reserva), ['accion' => 'autorizar', 'observacion' => 'Adelante'])->assertSessionHasNoErrors();

    expect($reserva->refresh()->estado_id)->toBe(E::AUTORIZADA)
        ->and($reserva->historial()->pluck('accion')->all())->toBe([
            HistorialReserva::CREADA,
            HistorialReserva::ENVIADA_COORDINADOR,
            HistorialReserva::APROBADA_COORDINADOR,
            HistorialReserva::AUTORIZADA,
        ]);
});

test('an invalid transition over HTTP is rejected with a message', function () {
    $reserva = Reserva::factory()->enEstado(E::AUTORIZADA)->create(['licenciatura_id' => $this->licenciatura->id]);

    $this->actingAs($this->admin)
        ->post(route('reservas.transicion', $reserva), ['accion' => 'denegar', 'observacion' => 'x'])
        ->assertSessionHasErrors('transicion');

    expect($reserva->refresh()->estado_id)->toBe(E::AUTORIZADA);
});

test('a teacher cannot authorize over HTTP', function () {
    $reserva = Reserva::factory()->enEstado(E::PENDIENTE_ADMIN)
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);

    $this->actingAs($this->docente)
        ->post(route('reservas.transicion', $reserva), ['accion' => 'autorizar', 'observacion' => 'yo mismo'])
        ->assertForbidden();
});

test('the detail page shows the actions each role may take', function () {
    $reserva = Reserva::factory()->conDetalle()->enEstado(E::PENDIENTE_COORDINADOR)
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);

    $this->actingAs($this->coordinador)->get(route('reservas.show', $reserva))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('reservas/Show')
            ->where('puede.editar', true)
            ->where('acciones.0.accion', 'aprobar'));

    $this->actingAs($this->docente)->get(route('reservas.show', $reserva))
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('puede.editar', false)
            ->where('acciones', []));
});

test('reservations are only visible to whom they concern', function () {
    $otroDocente = User::factory()->rol('docente')->create();
    $otroDocente->licenciaturas()->attach($this->licenciatura);

    $propia = Reserva::factory()->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $ajenaBorrador = Reserva::factory()->create(['usuario_creador_id' => $otroDocente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $ajenaEnviada = Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)->create(['usuario_creador_id' => $otroDocente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $otraCarrera = Reserva::factory()->enEstado(E::PENDIENTE_ADMIN)->create(['licenciatura_id' => $this->otraLicenciatura->id]);

    $ids = fn (User $u) => $this->actingAs($u)->get(route('solicitudes.index'))->viewData('page')['props']['solicitudes'];
    $idsDe = fn (User $u) => collect($ids($u))->pluck('id')->sort()->values()->all();

    expect($idsDe($this->docente))->toBe([$propia->id])
        ->and($idsDe($this->coordinador))->toBe([$ajenaEnviada->id])
        ->and($idsDe($this->admin))->toBe(collect([$ajenaEnviada->id, $otraCarrera->id])->sort()->values()->all());

    $this->actingAs($this->docente)->get(route('reservas.show', $ajenaEnviada))->assertForbidden();
    $this->actingAs($this->coordinador)->get(route('reservas.show', $ajenaBorrador))->assertForbidden();
    $this->actingAs($this->coordinador)->get(route('reservas.show', $otraCarrera))->assertForbidden();
    $this->actingAs($this->admin)->get(route('reservas.show', $otraCarrera))->assertOk();
});
