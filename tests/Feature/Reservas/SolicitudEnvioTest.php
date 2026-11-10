<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Requerimiento;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Módulo de solicitudes (etapa 3): «Guardar y enviar» con el flujo existente,
 * datos del formulario al editar y «Mis borradores».
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40]);

    $this->solicitud = fn (array $cambios = [], array $detalle = []): array => [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Taller de redes',
        'objetivo' => 'Configurar routers',
        'detalles' => [[
            'espacio_id' => $this->espacio->id,
            'fecha' => Date::tomorrow()->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '12:00',
            'cantidad_alumnos' => 20,
            ...$detalle,
        ]],
        ...$cambios,
    ];

    $this->toast = fn ($respuesta): array => $respuesta->baseResponse->getSession()->get('inertia.flash_data')['toast'] ?? [];
});

// --- Guardar y enviar ---

test('a teacher saves and sends to the coordinator in one step', function () {
    $respuesta = $this->actingAs($this->docente)->post(route('reservas.store'), ($this->solicitud)(['enviar' => true]));

    $reserva = Reserva::firstOrFail();
    $respuesta->assertRedirect(route('reservas.show', $reserva))->assertSessionHasNoErrors();

    expect($reserva->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and($reserva->historial()->pluck('accion')->all())->toBe([HistorialReserva::CREADA, HistorialReserva::ENVIADA_COORDINADOR])
        ->and(($this->toast)($respuesta)['message'])->toBe("Reserva {$reserva->folio} guardada y enviada a coordinación.");
});

test('a coordinator saves and sends their own complete request to administration', function () {
    $this->actingAs($this->coordinador)->post(route('reservas.store'), ($this->solicitud)(['enviar' => true]))
        ->assertSessionHasNoErrors();

    expect(Reserva::firstOrFail()->estado_id)->toBe(E::PENDIENTE_ADMIN);
});

test('a plain save keeps the draft, the send flag is never stored', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->solicitud)(['enviar' => false]))
        ->assertSessionHasNoErrors();

    expect(Reserva::firstOrFail()->estado_id)->toBe(E::BORRADOR)
        ->and(Reserva::firstOrFail()->historial()->count())->toBe(1);
});

test('if sending is not possible the draft is kept and the reason is shown', function () {
    $sinCoordinador = Licenciatura::factory()->create();
    $this->docente->licenciaturas()->attach($sinCoordinador);

    $respuesta = $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->solicitud)(['licenciatura_id' => $sinCoordinador->id, 'enviar' => true]));

    $reserva = Reserva::firstOrFail();
    $respuesta->assertRedirect(route('reservas.show', $reserva))
        ->assertSessionHasErrors(['transicion' => 'La licenciatura de esta reserva no tiene un coordinador asignado. Pide al administrador que asigne uno.']);

    expect($reserva->estado_id)->toBe(E::BORRADOR)
        ->and($reserva->detalles()->count())->toBe(1)
        ->and(($this->toast)($respuesta)['type'])->toBe('warning');
});

test('if the strict checks fail when sending to administration the draft is kept with its errors', function () {
    $respuesta = $this->actingAs($this->coordinador)
        ->post(route('reservas.store'), ($this->solicitud)(['enviar' => true], ['cantidad_alumnos' => 60]));

    $reserva = Reserva::firstOrFail();
    $respuesta->assertRedirect(route('reservas.show', $reserva))->assertSessionHasErrors('reserva.0');

    expect($reserva->estado_id)->toBe(E::BORRADOR)
        ->and(session('errors')->get('reserva.0')[0])->toContain('superan la capacidad');
});

test('editing a request under correction can resend it', function () {
    $reserva = Reserva::factory()->conDetalle(['espacio_id' => $this->espacio->id])->enEstado(E::CORRECCION)
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id, 'actividad_id' => $this->actividad->id]);

    $this->actingAs($this->docente)
        ->put(route('reservas.update', $reserva), ($this->solicitud)(['enviar' => true], ['id' => $reserva->detalles()->value('id')]))
        ->assertSessionHasNoErrors();

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

test('the form offers «save and send» only to whom may send now', function () {
    $this->actingAs($this->docente)->get(route('reservas.create'))
        ->assertInertia(fn (Assert $page) => $page->where('envio', 'Guardar y enviar'));

    $borrador = Reserva::factory()->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $this->actingAs($this->docente)->get(route('reservas.edit', $borrador))
        ->assertInertia(fn (Assert $page) => $page->where('envio', 'Guardar y enviar a coordinación'));

    $pendiente = Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $this->actingAs($this->coordinador)->get(route('reservas.edit', $pendiente))
        ->assertInertia(fn (Assert $page) => $page->where('envio', null));

    $propia = Reserva::factory()->create(['usuario_creador_id' => $this->coordinador->id, 'licenciatura_id' => $this->licenciatura->id]);
    $this->actingAs($this->coordinador)->get(route('reservas.edit', $propia))
        ->assertInertia(fn (Assert $page) => $page->where('envio', 'Guardar y enviar a administración'));
});

// --- Edición: se recupera todo lo guardado ---

test('editing restores every block with its teacher, space, schedule and requirements', function () {
    $proyector = Requerimiento::create(['nombre' => 'Proyector', 'estatus' => 'activo']);
    $otroEspacio = Espacio::factory()->create();
    $otroEspacio->requerimientos()->attach($proyector->id, ['cantidad_disponible' => 3]);
    $manana = Date::tomorrow()->toDateString();

    $this->actingAs($this->docente)->post(route('reservas.store'), [
        ...($this->solicitud)(),
        'detalles' => [
            ['espacio_id' => $this->espacio->id, 'fecha' => $manana, 'hora_inicio' => '08:00', 'hora_fin' => '09:30', 'cantidad_alumnos' => 15, 'docente_responsable_id' => $this->coordinador->id],
            ['espacio_id' => $otroEspacio->id, 'fecha' => $manana, 'hora_inicio' => '10:00', 'hora_fin' => '11:00', 'docente_responsable_id' => $this->docente->id, 'requerimientos' => [['requerimiento_id' => $proyector->id, 'cantidad' => 3]]],
        ],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->docente)->get(route('reservas.edit', Reserva::firstOrFail()))
        ->assertInertia(fn (Assert $page) => $page
            ->has('reserva.detalles', 2)
            ->where('reserva.detalles.0.espacio_id', $this->espacio->id)
            ->where('reserva.detalles.0.hora_inicio', '08:00')
            ->where('reserva.detalles.0.hora_fin', '09:30')
            ->where('reserva.detalles.0.docente_responsable_id', $this->coordinador->id)
            ->where('reserva.detalles.0.requerimientos', [])
            ->where('reserva.detalles.1.espacio_id', $otroEspacio->id)
            ->where('reserva.detalles.1.fecha', $manana)
            ->where('reserva.detalles.1.docente_responsable_id', $this->docente->id)
            ->where('reserva.detalles.1.requerimientos', [['requerimiento_id' => $proyector->id, 'cantidad' => 3]]));
});

// --- Mis borradores ---

test('«my drafts» lists only your own drafts', function () {
    $mio = Reserva::factory()->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $deOtro = User::factory()->rol('docente')->create();
    $deOtro->licenciaturas()->attach($this->licenciatura);
    $ajeno = Reserva::factory()->create(['usuario_creador_id' => $deOtro->id, 'licenciatura_id' => $this->licenciatura->id]);

    $this->actingAs($this->docente)->get(route('solicitudes.index', ['estado_id' => E::BORRADOR]))
        ->assertInertia(fn (Assert $page) => $page->where('solicitudes', fn ($filas) => collect($filas)->pluck('id')->all() === [$mio->id]));

    // El coordinador tampoco ve borradores ajenos, solo los suyos (ninguno aquí).
    $this->actingAs($this->coordinador)->get(route('solicitudes.index', ['estado_id' => E::BORRADOR]))
        ->assertInertia(fn (Assert $page) => $page->where('solicitudes', fn ($filas) => ! collect($filas)->pluck('id')->contains($ajeno->id)));
});
