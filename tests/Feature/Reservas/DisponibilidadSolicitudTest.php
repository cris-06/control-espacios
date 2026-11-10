<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\Licenciatura;
use App\Models\Requerimiento;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\DisponibilidadEspacioService;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * Módulo de solicitudes (etapa 2): disponibilidad al guardar, consulta en vivo,
 * revalidación estricta y autorización protegida contra choques simultáneos.
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->otraLicenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->admin = User::factory()->rol('admin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['nombre' => 'Laboratorio 1', 'capacidad' => 40]);
    $this->manana = Date::tomorrow()->toDateString();

    // Reserva de otra persona en el mismo espacio, mañana de 10:00 a 12:00.
    $this->ajena = function (int $estado, ?int $licenciaturaId = null, array $detalle = []): Reserva {
        $creador = User::factory()->rol('docente')->create();
        $licenciaturaId ??= $this->licenciatura->id;
        $creador->licenciaturas()->attach($licenciaturaId);

        return Reserva::factory()->enEstado($estado)->conDetalle([
            'espacio_id' => $this->espacio->id,
            'fecha' => $this->manana,
            'estado_detalle' => $estado === E::AUTORIZADA ? ReservaDetalle::AUTORIZADA : ReservaDetalle::PENDIENTE,
            ...$detalle,
        ])->create(['usuario_creador_id' => $creador->id, 'licenciatura_id' => $licenciaturaId]);
    };

    $this->solicitud = fn (array $detalles = []): array => [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Taller de redes',
        'objetivo' => 'Configurar routers',
        'detalles' => $detalles ?: [[
            'espacio_id' => $this->espacio->id,
            'fecha' => $this->manana,
            'hora_inicio' => '11:00',
            'hora_fin' => '13:00',
            'cantidad_alumnos' => 20,
        ]],
    ];

    $this->toast = fn ($respuesta): array => $respuesta->baseResponse->getSession()->get('inertia.flash_data')['toast'] ?? [];

    $this->verificar = fn (array $params = []) => route('disponibilidad.verificar', [
        'espacio_id' => $this->espacio->id,
        'fecha' => $this->manana,
        'hora_inicio' => '11:00',
        'hora_fin' => '13:00',
        ...$params,
    ]);
});

// --- Guardar nunca se bloquea por disponibilidad ---

test('a draft is saved even if it overlaps a pending request, with a warning', function () {
    $pendiente = ($this->ajena)(E::PENDIENTE_ADMIN);

    $respuesta = $this->actingAs($this->docente)->post(route('reservas.store'), ($this->solicitud)());

    $respuesta->assertSessionHasNoErrors();
    $toast = ($this->toast)($respuesta);

    expect(Reserva::query()->where('usuario_creador_id', $this->docente->id)->value('estado_id'))->toBe(E::BORRADOR)
        ->and($toast['type'])->toBe('warning')
        ->and($toast['message'])->toContain('en revisión; podría haber conflicto')
        // La reserva ajena es de otro docente: no se revela su folio.
        ->and($toast['message'])->toContain('otra reserva')
        ->and($toast['message'])->not->toContain($pendiente->folio ?? Reserva::folioPara($pendiente->id));
});

test('a draft is saved even if it overlaps an authorized reservation, warning it cannot be authorized', function () {
    ($this->ajena)(E::AUTORIZADA);

    $respuesta = $this->actingAs($this->docente)->post(route('reservas.store'), ($this->solicitud)());

    $respuesta->assertSessionHasNoErrors();
    expect(Reserva::query()->where('usuario_creador_id', $this->docente->id)->exists())->toBeTrue()
        ->and(($this->toast)($respuesta)['message'])->toContain('no podrá autorizarse en ese horario');
});

test('overlapping blocks of the same request are saved with a warning', function () {
    $bloque = ['espacio_id' => $this->espacio->id, 'fecha' => $this->manana, 'cantidad_alumnos' => 10];

    $respuesta = $this->actingAs($this->docente)->post(route('reservas.store'), ($this->solicitud)([
        [...$bloque, 'hora_inicio' => '08:00', 'hora_fin' => '10:00'],
        [...$bloque, 'hora_inicio' => '09:00', 'hora_fin' => '11:00'],
    ]));

    $respuesta->assertSessionHasNoErrors();
    expect(Reserva::firstOrFail()->detalles()->count())->toBe(2)
        ->and(($this->toast)($respuesta)['message'])->toContain('Espacio 1 y espacio 2 se traslapan');
});

test('a free slot saves with a plain success message', function () {
    ($this->ajena)(E::AUTORIZADA, detalle: ['hora_inicio' => '08:00:00', 'hora_fin' => '11:00:00']);
    ($this->ajena)(E::BORRADOR);
    ($this->ajena)(E::DENEGADA, detalle: ['estado_detalle' => ReservaDetalle::DENEGADA]);

    $respuesta = $this->actingAs($this->docente)->post(route('reservas.store'), ($this->solicitud)());

    // 08:00-11:00 termina justo cuando empieza 11:00-13:00; borradores y denegadas no ocupan.
    expect(($this->toast)($respuesta))->toBe(['type' => 'success', 'message' => 'Reserva '.Reserva::latest('id')->value('folio').' guardada como borrador.']);
});

// --- Autorización: bloqueo y nueva comprobación en la misma transacción ---

test('two conflicting requests cannot both be authorized', function () {
    $primera = ($this->ajena)(E::PENDIENTE_ADMIN);
    $segunda = ($this->ajena)(E::PENDIENTE_ADMIN, detalle: ['hora_inicio' => '11:00:00', 'hora_fin' => '13:00:00']);
    $workflow = app(W::class);

    $workflow->ejecutar($primera, $this->admin, W::AUTORIZAR, 'Autorizada.');

    try {
        $workflow->ejecutar($segunda, $this->admin, W::AUTORIZAR, 'Autorizada.');
        $this->fail('La segunda autorización debió rechazarse.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain('choca con la reserva autorizada '.$primera->folio);
    }

    expect($primera->refresh()->estado_id)->toBe(E::AUTORIZADA)
        ->and($segunda->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($segunda->detalles()->value('estado_detalle'))->toBe(ReservaDetalle::PENDIENTE)
        ->and($segunda->historial()->count())->toBe(0);
});

test('authorizing locks the spaces before checking the conflicts again', function () {
    $reserva = ($this->ajena)(E::PENDIENTE_ADMIN);

    $disponibilidad = Mockery::mock(DisponibilidadEspacioService::class)->makePartial();
    $disponibilidad->shouldReceive('bloquearEspacios')->once()->with([$this->espacio->id])->ordered()->passthru();
    $disponibilidad->shouldReceive('choques')->once()->withArgs(fn (...$args) => end($args) === true)->ordered()->passthru();
    $this->app->instance(DisponibilidadEspacioService::class, $disponibilidad);

    app(W::class)->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Autorizada.');

    expect($reserva->refresh()->estado_id)->toBe(E::AUTORIZADA);
});

test('only authorizing locks spaces: the coordinator approval just warns', function () {
    $propia = Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)
        ->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => $this->manana])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    ($this->ajena)(E::AUTORIZADA);

    $disponibilidad = Mockery::mock(DisponibilidadEspacioService::class)->makePartial();
    $disponibilidad->shouldNotReceive('bloquearEspacios');
    $this->app->instance(DisponibilidadEspacioService::class, $disponibilidad);

    $avisos = app(W::class)->ejecutar($propia, $this->coordinador, W::APROBAR);

    expect($propia->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($avisos)->toHaveCount(1);
});

test('a requirement withdrawn after saving blocks sending to administration', function () {
    $proyector = Requerimiento::create(['nombre' => 'Proyector', 'estatus' => 'activo']);
    $this->espacio->requerimientos()->attach($proyector->id, ['cantidad_disponible' => 2]);
    $reserva = Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)
        ->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => $this->manana])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $reserva->detalles()->firstOrFail()->requerimientos()->attach($proyector->id, ['cantidad' => 2]);

    // El administrador reduce la cantidad disponible después de que se guardó la solicitud.
    $this->espacio->requerimientos()->updateExistingPivot($proyector->id, ['cantidad_disponible' => 1]);

    try {
        app(W::class)->ejecutar($reserva, $this->coordinador, W::APROBAR);
        $this->fail('Debió rechazarse por el requerimiento.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->all())->toContain('Espacio 1: solo hay 1 de «Proyector» en este espacio.');
    }

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

test('a responsible teacher who left the program blocks sending to administration', function () {
    $responsable = User::factory()->rol('docente')->create();
    $responsable->licenciaturas()->attach($this->licenciatura);
    $reserva = Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)
        ->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => $this->manana])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $reserva->detalles()->update(['docente_responsable_id' => $responsable->id]);
    $responsable->licenciaturas()->detach();

    expect(fn () => app(W::class)->ejecutar($reserva, $this->coordinador, W::APROBAR))->toThrow(ValidationException::class);
});

// --- Consulta en vivo ---

test('the live check tells apart free, pending, occupied and out of service', function () {
    $this->actingAs($this->docente);

    $this->getJson(($this->verificar)())->assertOk()->assertJson(['situacion' => 'libre', 'choques' => []]);

    ($this->ajena)(E::PENDIENTE_COORDINADOR);
    $this->getJson(($this->verificar)())->assertOk()->assertJsonPath('situacion', 'pendiente');

    ($this->ajena)(E::AUTORIZADA, detalle: ['hora_inicio' => '12:00:00', 'hora_fin' => '14:00:00']);
    $this->getJson(($this->verificar)())->assertOk()->assertJsonPath('situacion', 'ocupado')->assertJsonCount(2, 'choques');

    // Termina justo cuando empieza: no choca.
    $this->getJson(($this->verificar)(['hora_inicio' => '14:00', 'hora_fin' => '15:00']))->assertJsonPath('situacion', 'libre');

    $this->espacio->update(['estatus' => 'mantenimiento']);
    $this->getJson(($this->verificar)(['hora_inicio' => '16:00', 'hora_fin' => '17:00']))->assertJsonPath('situacion', 'fuera_de_servicio');
});

test('the live check only reveals schedule and status of reservations you cannot see', function () {
    $ajena = ($this->ajena)(E::AUTORIZADA, $this->otraLicenciatura->id);
    $propia = Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)
        ->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => $this->manana, 'hora_inicio' => '12:00:00', 'hora_fin' => '13:00:00'])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);

    $json = $this->actingAs($this->docente)->getJson(($this->verificar)())->assertOk()->json();

    expect($json['choques'])->toBe([
        ['hora_inicio' => '10:00', 'hora_fin' => '12:00', 'estado' => 'autorizada', 'folio' => null],
        ['hora_inicio' => '12:00', 'hora_fin' => '13:00', 'estado' => 'pendiente', 'folio' => $propia->folio ?? Reserva::folioPara($propia->id)],
    ])
        ->and(json_encode($json))->not->toContain($ajena->nombre_actividad)
        ->and(json_encode($json))->not->toContain($ajena->objetivo);
});

test('the live check excludes the reservation being edited', function () {
    $propia = Reserva::factory()->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => $this->manana])
        ->enEstado(E::PENDIENTE_COORDINADOR)
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);

    // El coordinador la está completando: no choca consigo misma.
    $this->actingAs($this->coordinador)
        ->getJson(($this->verificar)(['reserva_id' => $propia->id]))
        ->assertOk()
        ->assertJsonPath('situacion', 'libre');
});

test('the live check is only for whom may request or edit', function () {
    $this->actingAs($this->admin)->getJson(($this->verificar)())->assertForbidden();

    $ajena = ($this->ajena)(E::PENDIENTE_COORDINADOR, $this->otraLicenciatura->id);
    $this->actingAs($this->docente)->getJson(($this->verificar)(['reserva_id' => $ajena->id]))->assertForbidden();
    $this->actingAs($this->docente)->getJson(($this->verificar)(['reserva_id' => 999999]))->assertForbidden();

    $this->getJson(($this->verificar)())->assertOk();
});

test('the live check validates its input', function () {
    $this->actingAs($this->docente)
        ->getJson(($this->verificar)(['hora_inicio' => '13:00', 'hora_fin' => '11:00', 'fecha' => 'mañana']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hora_fin' => 'La hora de fin debe ser posterior a la de inicio.', 'fecha' => 'La fecha no es válida.']);

    auth()->logout();
    $this->getJson(($this->verificar)())->assertUnauthorized();
});
