<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva as H;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Formato 10, etapa 2: folio institucional {SIGLAS}-{AAAAMMDD}-{ID}.
 * Lo genera el servidor UNA vez al crear (siglas de la licenciatura + fecha de creación guardada
 * + id con al menos 5 dígitos). Sin siglas, la reserva solo tiene RES. Nunca se regenera.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-11 10:00:00'));

    $this->lsc = Licenciatura::factory()->create(['nombre' => 'Sistemas Computacionales', 'siglas' => 'LSC']);
    $this->ldp = Licenciatura::factory()->create(['nombre' => 'Derecho', 'siglas' => 'LDP']);
    $this->sinSiglas = Licenciatura::factory()->create(['nombre' => 'Psicología']);

    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach([$this->lsc->id, $this->ldp->id, $this->sinSiglas->id]);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach([$this->lsc->id, $this->ldp->id, $this->sinSiglas->id]);
    $this->admin = User::factory()->rol('admin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40]);
    $this->workflow = app(W::class);

    $this->datos = fn (Licenciatura $licenciatura, array $extra = []): array => [
        'licenciatura_id' => $licenciatura->id,
        'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Jornada Académica',
        'objetivo' => 'Brindar un espacio académico',
        'detalles' => [[
            'espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20,
        ]],
        ...$extra,
    ];

    // Crea por la ruta del formulario, como lo haría la docente.
    $this->crear = function (Licenciatura $licenciatura, array $extra = []): Reserva {
        $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)($licenciatura, $extra))->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };

    $this->esperado = fn (string $siglas, string $fecha, Reserva $reserva): string => sprintf('%s-%s-%05d', $siglas, $fecha, $reserva->id);
});

// --- Generación ---

test('it is generated on creation from the program acronym, the creation date and the id', function () {
    $reserva = ($this->crear)($this->lsc);

    expect($reserva->folio_institucional)->toBe(($this->esperado)('LSC', '20261111', $reserva))
        ->and($reserva->folio_institucional)->toMatch('/^LSC-20261111-\d{5}$/')
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and($reserva->fecha_creacion?->toDateTimeString())->toBe('2026-11-11 10:00:00');
});

test('without an acronym the reservation is created normally with only its RES folio', function () {
    $reserva = ($this->crear)($this->sinSiglas);

    expect($reserva->folio_institucional)->toBeNull()
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and($reserva->estado_id)->toBe(E::BORRADOR)
        ->and($reserva->historial()->pluck('accion')->all())->toBe([H::CREADA]);
});

test('each program uses its own acronym', function () {
    $a = ($this->crear)($this->lsc);
    $b = ($this->crear)($this->ldp);

    expect($a->folio_institucional)->toBe(($this->esperado)('LSC', '20261111', $a))
        ->and($b->folio_institucional)->toBe(($this->esperado)('LDP', '20261111', $b));
});

test('two reservations of the same program on the same day get different folios', function () {
    $a = ($this->crear)($this->lsc);
    $b = ($this->crear)($this->lsc);

    expect($a->folio_institucional)->not->toBe($b->folio_institucional)
        ->and($a->folio_institucional)->toBe(($this->esperado)('LSC', '20261111', $a))
        ->and($b->folio_institucional)->toBe(($this->esperado)('LSC', '20261111', $b));
});

test('the date part follows the creation date', function () {
    $a = ($this->crear)($this->lsc);
    $this->travelTo(Date::parse('2026-11-20 08:15:00'));
    $b = ($this->crear)($this->lsc);

    expect($a->folio_institucional)->toBe(($this->esperado)('LSC', '20261111', $a))
        ->and($b->folio_institucional)->toBe(($this->esperado)('LSC', '20261120', $b));
});

test('near midnight the date is the project local date, not UTC', function () {
    // 23:59:59 en America/Mexico_City ya es el día siguiente en UTC.
    $this->travelTo(Date::parse('2026-11-11 23:59:59'));
    $antes = ($this->crear)($this->lsc);

    $this->travelTo(Date::parse('2026-11-12 00:00:00'));
    $despues = ($this->crear)($this->lsc);

    expect(config('app.timezone'))->toBe('America/Mexico_City')
        ->and($antes->folio_institucional)->toBe(($this->esperado)('LSC', '20261111', $antes))
        ->and($despues->folio_institucional)->toBe(($this->esperado)('LSC', '20261112', $despues));
});

test('the date comes from the stored creation date', function () {
    $reserva = ($this->crear)($this->lsc);
    $guardada = Reserva::query()->whereKey($reserva->id)->value('fecha_creacion');

    expect($reserva->folio_institucional)->toBe(Reserva::folioInstitucionalPara('LSC', $guardada, $reserva->id));
});

test('ids with more than five digits are not truncated', function () {
    Reserva::factory()->create(['id' => 123455, 'licenciatura_id' => $this->sinSiglas->id]);
    $reserva = ($this->crear)($this->lsc);

    expect($reserva->id)->toBe(123456)
        ->and($reserva->folio_institucional)->toBe('LSC-20261111-123456')
        ->and(Reserva::folioInstitucionalPara('LDP', Date::parse('2026-01-02 00:00:00'), 7))->toBe('LDP-20260102-00007')
        ->and(Reserva::folioInstitucionalPara('LDP', Date::parse('2026-01-02 00:00:00'), 1234567))->toBe('LDP-20260102-1234567');
});

test('the database rejects a duplicated folio as an extra safeguard', function () {
    $a = ($this->crear)($this->lsc);
    $b = ($this->crear)($this->ldp);

    expect(fn () => Reserva::query()->whereKey($b->id)->update(['folio_institucional' => $a->folio_institucional]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Reserva::query()->whereKey($b->id)->value('folio_institucional'))->toBe(($this->esperado)('LDP', '20261111', $b));
});

test('several reservations without acronym can coexist (NULL is not duplicated)', function () {
    ($this->crear)($this->sinSiglas);
    ($this->crear)($this->sinSiglas);

    expect(Reserva::query()->whereNull('folio_institucional')->count())->toBe(2);
});

// --- Inmutabilidad ---

test('the folio cannot be set or changed through POST, PUT or PATCH', function () {
    $reserva = ($this->crear)($this->lsc, ['folio_institucional' => 'FALSO-20200101-00001', 'folio' => 'RES-99999']);
    $original = ($this->esperado)('LSC', '20261111', $reserva);
    expect($reserva->folio_institucional)->toBe($original)->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id));

    $detalle = $reserva->detalles()->firstOrFail();
    $bloques = [['id' => $detalle->id, 'espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 25]];

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)($this->lsc, ['detalles' => $bloques, 'folio_institucional' => 'FALSO-PUT']))->assertSessionHasNoErrors();
    $this->actingAs($this->docente)->patch(route('reservas.update', $reserva), ($this->datos)($this->lsc, ['detalles' => $bloques, 'folio_institucional' => 'FALSO-PATCH']))->assertSessionHasNoErrors();
    $this->actingAs($this->docente)->post(route('reservas.transicion', $reserva), ['accion' => W::ENVIAR, 'folio_institucional' => 'FALSO-ENVIO'])->assertSessionHasNoErrors();

    expect($reserva->refresh()->folio_institucional)->toBe($original)
        ->and($reserva->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

test('changing the program later does not regenerate the folio', function () {
    $reserva = ($this->crear)($this->lsc);
    $original = $reserva->folio_institucional;
    $detalle = $reserva->detalles()->firstOrFail();

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)($this->ldp, ['detalles' => [[
        'id' => $detalle->id, 'espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20,
    ]]]))->assertSessionHasNoErrors();

    expect($reserva->refresh()->licenciatura_id)->toBe($this->ldp->id)
        ->and($reserva->folio_institucional)->toBe($original)
        ->and($original)->toStartWith('LSC-');
});

test('the whole flow keeps the folio, the history and the notifications as before', function () {
    $reserva = ($this->crear)($this->lsc);
    $original = $reserva->folio_institucional;

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Precisa el objetivo');
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Autorizada');

    expect($reserva->refresh()->folio_institucional)->toBe($original)
        ->and($reserva->estado_id)->toBe(E::AUTORIZADA)
        ->and($reserva->historial()->pluck('accion')->all())->toBe([
            H::CREADA, H::ENVIADA_COORDINADOR, H::CORRECCION_SOLICITADA, H::ENVIADA_COORDINADOR, H::APROBADA_COORDINADOR, H::AUTORIZADA,
        ]);

    // Las notificaciones siguen citando el folio RES (por ahora no cambia su texto).
    $mensajes = Notificacion::query()->where('reserva_id', $reserva->id)->pluck('mensaje');
    expect($mensajes)->toHaveCount(5)
        ->and($mensajes->every(fn (string $m): bool => str_contains($m, $reserva->folio) && ! str_contains($m, $original)))->toBeTrue();
});

// --- Siglas registradas o cambiadas después ---

test('registering an acronym later only affects new reservations', function () {
    $vieja = ($this->crear)($this->sinSiglas);

    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $this->sinSiglas), ['nombre' => 'Psicología', 'siglas' => 'lps'])->assertSessionHasNoErrors();
    $nueva = ($this->crear)($this->sinSiglas);

    expect($vieja->refresh()->folio_institucional)->toBeNull()
        ->and($nueva->folio_institucional)->toBe(($this->esperado)('LPS', '20261111', $nueva));
});

test('changing an acronym keeps the folios already assigned', function () {
    $vieja = ($this->crear)($this->lsc);
    $original = $vieja->folio_institucional;

    $this->actingAs($this->admin)->put(route('admin.licenciaturas.update', $this->lsc), ['nombre' => 'Sistemas Computacionales', 'siglas' => 'ISC'])->assertSessionHasNoErrors();
    $nueva = ($this->crear)($this->lsc);

    expect($vieja->refresh()->folio_institucional)->toBe($original)
        ->and($nueva->folio_institucional)->toBe(($this->esperado)('ISC', '20261111', $nueva));
});

test('an acronym that bypassed validation is never used', function () {
    Licenciatura::query()->whereKey($this->sinSiglas->id)->update(['siglas' => 'lp s']);

    expect(($this->crear)($this->sinSiglas)->folio_institucional)->toBeNull();
});

// --- Compatibilidad y pantallas ---

test('old reservations without a folio keep working and show their RES folio', function () {
    $antigua = Reserva::factory()->conDetalle(['espacio_id' => $this->espacio->id])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->lsc->id, 'folio' => 'RES-00001']);

    expect($antigua->folio_institucional)->toBeNull();

    $this->actingAs($this->docente)->get(route('reservas.show', $antigua))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('reserva.folio', 'RES-00001')->where('reserva.folio_institucional', null));

    $this->actingAs($this->docente)->get(route('reservas.edit', $antigua))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('reserva.folio', 'RES-00001')->where('reserva.folio_institucional', null));
});

test('the detail and the form receive both folios, read only', function () {
    $reserva = ($this->crear)($this->lsc);

    $this->actingAs($this->docente)->get(route('reservas.show', $reserva))
        ->assertInertia(fn (Assert $p) => $p->where('reserva.folio', $reserva->folio)->where('reserva.folio_institucional', $reserva->folio_institucional));

    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))
        ->assertInertia(fn (Assert $p) => $p->where('reserva.folio', $reserva->folio)->where('reserva.folio_institucional', $reserva->folio_institucional));

    $plantilla = (string) file_get_contents(resource_path('js/components/EncabezadoFormato.vue'));
    expect($plantilla)->not->toContain('<input')->not->toContain('v-model');
});
