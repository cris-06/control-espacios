<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Requerimiento;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\AnticipacionReserva;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Formato 10: anticipación mínima de 10 días naturales (fecha del servidor, America/Mexico_City).
 * Primer envío: todos los bloques ≥ hoy + 10. Ya enviada: un bloque nuevo o con otro espacio,
 * fecha u horario debe cumplirla el día del cambio; los demás conservan su fecha y al reenviar
 * solo se rechaza una fecha pasada. Los borradores se guardan sin restricción.
 *
 * Hoy (simulado) = martes 10/11/2026 → fecha mínima = 20/11/2026.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-10 09:30:00'));

    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->admin = User::factory()->rol('admin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->workflow = app(W::class);

    $this->auditorio = Espacio::factory()->create(['nombre' => 'Auditorio 1', 'capacidad' => 40]);
    $this->anexo = Espacio::factory()->create(['nombre' => 'Anexo', 'capacidad' => 40]);
    $this->sillas = Requerimiento::create(['nombre' => 'Sillas', 'estatus' => 'activo']);
    $this->auditorio->requerimientos()->attach($this->sillas->id, ['cantidad_disponible' => 50]);

    $this->bloque = fn (string $fecha, ?Espacio $espacio = null, string $inicio = '08:00', string $fin = '10:00', array $extra = []): array => [
        'espacio_id' => ($espacio ?? $this->auditorio)->id,
        'fecha' => $fecha,
        'hora_inicio' => $inicio,
        'hora_fin' => $fin,
        'cantidad_alumnos' => 20,
        ...$extra,
    ];

    $this->datos = fn (array $detalles, array $extra = []): array => [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Jornada Académica',
        'objetivo' => 'Brindar un espacio académico interdisciplinario',
        'detalles' => $detalles,
        ...$extra,
    ];

    // Borrador creado por la ruta normal del formulario.
    $this->crear = function (User $quien, array $detalles): Reserva {
        $this->actingAs($quien)->post(route('reservas.store'), ($this->datos)($detalles))->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };

    $this->enviar = fn (User $quien, Reserva $reserva) => $this->actingAs($quien)
        ->post(route('reservas.transicion', $reserva), ['accion' => W::ENVIAR]);

    // Los bloques tal como los manda el formulario de edición (con su id; sin requerimientos = se conservan).
    $this->actuales = fn (Reserva $reserva): array => ReservaDetalle::query()->where('reserva_id', $reserva->id)
        ->orderBy('fecha')->orderBy('hora_inicio')->get()
        ->map(fn (ReservaDetalle $d): array => [
            'id' => $d->id,
            'espacio_id' => $d->espacio_id,
            'fecha' => $d->fecha->toDateString(),
            'hora_inicio' => ReservaDetalle::hhmm($d->hora_inicio),
            'hora_fin' => ReservaDetalle::hhmm($d->hora_fin),
            'cantidad_alumnos' => $d->cantidad_alumnos,
        ])->all();

    $this->editar = fn (User $quien, Reserva $reserva, array $detalles, array $extra = []) => $this->actingAs($quien)
        ->put(route('reservas.update', $reserva), ($this->datos)($detalles, $extra));

    // Todo lo que un rechazo no debe tocar.
    $this->huella = fn (Reserva $reserva): array => [
        'reserva' => Arr::only(Reserva::query()->findOrFail($reserva->id)->toArray(), ['estado_id', 'fecha_entrega_formato', 'objetivo', 'usuario_coordinador_id']),
        'detalles' => ReservaDetalle::query()->where('reserva_id', $reserva->id)->orderBy('id')->get()
            ->map(fn (ReservaDetalle $d): array => Arr::only($d->toArray(), ['id', 'espacio_id', 'fecha', 'hora_inicio', 'hora_fin', 'cantidad_alumnos', 'estado_detalle']))->all(),
        'historial' => HistorialReserva::query()->where('reserva_id', $reserva->id)->pluck('accion')->all(),
        'notificaciones' => Notificacion::query()->count(),
    ];

    // Enviada el 10/11 para el 25/11 (15 días) y devuelta a corrección por coordinación;
    // luego pasa el tiempo hasta el 18/11: la fecha original queda a 7 días.
    $this->enCorreccion = function (): Reserva {
        $reserva = ($this->crear)($this->docente, [($this->bloque)('2026-11-25')]);
        ($this->enviar)($this->docente, $reserva)->assertSessionHasNoErrors();
        $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Precisa el objetivo');
        $this->travelTo(Date::parse('2026-11-18 10:00:00'));

        return $reserva->refresh();
    };
});

// --- Primer envío ---

test('first send: 9 days is rejected, 10 and 11 days are accepted', function (string $fecha, bool $valida) {
    $reserva = ($this->crear)($this->docente, [($this->bloque)($fecha)]);
    $antes = ($this->huella)($reserva);

    $respuesta = ($this->enviar)($this->docente, $reserva);

    if ($valida) {
        $respuesta->assertSessionHasNoErrors();
        expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
            ->and($reserva->fecha_entrega_formato?->toDateString())->toBe('2026-11-10');
    } else {
        $respuesta->assertSessionHasErrors([
            'reserva.0' => 'Espacio 1 (Auditorio 1, 19/11/2026): se requieren 10 días de anticipación; elige una fecha a partir del 20/11/2026.',
        ]);
        expect(($this->huella)($reserva))->toBe($antes);
    }
})->with([
    '9 días' => ['2026-11-19', false],
    '10 días' => ['2026-11-20', true],
    '11 días' => ['2026-11-21', true],
]);

test('a coordinator own request to administration follows the same rule', function (string $fecha, bool $valida) {
    $reserva = ($this->crear)($this->coordinador, [($this->bloque)($fecha)]);
    $antes = ($this->huella)($reserva);

    $respuesta = ($this->enviar)($this->coordinador, $reserva);

    if ($valida) {
        $respuesta->assertSessionHasNoErrors();
        expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
            ->and($reserva->fecha_entrega_formato?->toDateString())->toBe('2026-11-10');
    } else {
        $respuesta->assertSessionHasErrors('reserva.0');
        expect(session('errors')->get('reserva.0')[0])->toContain('10 días de anticipación')
            ->and(($this->huella)($reserva))->toBe($antes);
    }
})->with([
    '9 días' => ['2026-11-19', false],
    '10 días' => ['2026-11-20', true],
]);

test('every block must comply and the message names the failing one', function () {
    $reserva = ($this->crear)($this->docente, [
        ($this->bloque)('2026-11-25'),
        ($this->bloque)('2026-11-19', $this->anexo, '12:00', '14:00'),
        ($this->bloque)('2026-12-01', $this->anexo),
    ]);
    $antes = ($this->huella)($reserva);

    ($this->enviar)($this->docente, $reserva)->assertSessionHasErrors([
        'reserva.0' => 'Espacio 1 (Anexo, 19/11/2026): se requieren 10 días de anticipación; elige una fecha a partir del 20/11/2026.',
    ])->assertSessionDoesntHaveErrors('reserva.1');

    expect(($this->huella)($reserva))->toBe($antes);
});

test('drafts are saved and edited with dates that do not comply yet', function () {
    $reserva = ($this->crear)($this->docente, [($this->bloque)('2026-11-13')]);

    ($this->editar)($this->docente, $reserva, [
        [...($this->actuales)($reserva)[0], 'fecha' => '2026-11-12'],
        ($this->bloque)('2026-11-11', $this->anexo),
    ])->assertSessionHasNoErrors();

    expect($reserva->refresh()->estado_id)->toBe(E::BORRADOR)
        ->and($reserva->fecha_entrega_formato)->toBeNull()
        ->and($reserva->detalles()->pluck('fecha')->map->toDateString()->all())->toBe(['2026-11-11', '2026-11-12']);
});

test('save and send keeps the draft but does not send it, nor records delivery, history or notifications', function () {
    $respuesta = $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->datos)([($this->bloque)('2026-11-19')], ['enviar' => true]));

    $reserva = Reserva::query()->firstOrFail();
    $respuesta->assertRedirect(route('reservas.show', $reserva))->assertSessionHasErrors('reserva.0');

    // Comportamiento documentado: «Guardar y enviar» guarda primero el borrador y el envío
    // va en su propia transacción; si el envío se rechaza, el borrador queda guardado.
    expect($reserva->estado_id)->toBe(E::BORRADOR)
        ->and($reserva->fecha_entrega_formato)->toBeNull()
        ->and($reserva->detalles()->count())->toBe(1)
        ->and($reserva->historial()->pluck('accion')->all())->toBe([HistorialReserva::CREADA])
        ->and(Notificacion::query()->count())->toBe(0);
});

test('the minimum date follows the server clock across midnight', function () {
    $reserva = ($this->crear)($this->docente, [($this->bloque)('2026-11-20')]);

    $this->travelTo(Date::parse('2026-11-11 00:00:00'));
    ($this->enviar)($this->docente, $reserva)->assertSessionHasErrors('reserva.0');

    $this->travelTo(Date::parse('2026-11-10 23:59:59'));
    ($this->enviar)($this->docente, $reserva)->assertSessionHasNoErrors();
});

test('the form receives the minimum date computed by the server', function () {
    $this->actingAs($this->docente)->get(route('reservas.create'))
        ->assertInertia(fn (Assert $p) => $p->where('anticipacion', ['dias' => 10, 'fecha_minima' => '2026-11-20', 'ya_enviada' => false]));

    $reserva = ($this->enCorreccion)();

    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))
        ->assertInertia(fn (Assert $p) => $p->where('anticipacion', ['dias' => 10, 'fecha_minima' => '2026-11-28', 'ya_enviada' => true]));

    expect(AnticipacionReserva::DIAS)->toBe(10);
});

// --- Correcciones y reenvíos ---

test('unchanged original dates are kept on resend even if fewer than 10 days remain', function () {
    $reserva = ($this->enCorreccion)();

    // Cambian objetivo, alumnos, descripción y requerimientos; no el espacio ni el horario.
    ($this->editar)($this->docente, $reserva, [[
        ...($this->actuales)($reserva)[0],
        'cantidad_alumnos' => 35,
        'descripcion_actividad' => 'Conferencia inaugural',
        'requerimientos' => [['requerimiento_id' => $this->sillas->id, 'cantidad' => 30]],
    ]], ['objetivo' => 'Objetivo precisado'])->assertSessionHasNoErrors();

    ($this->enviar)($this->docente, $reserva)->assertSessionHasNoErrors();

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and($reserva->fecha_entrega_formato?->toDateString())->toBe('2026-11-10')
        ->and($reserva->detalles()->value('fecha')->toDateString())->toBe('2026-11-25');
});

test('changing the date, space, start or end time restarts the 10 days and nothing is saved', function (string $campo, mixed $valor) {
    $reserva = ($this->enCorreccion)();
    $antes = ($this->huella)($reserva);
    $valor = $valor === 'anexo' ? $this->anexo->id : $valor;

    ($this->editar)($this->docente, $reserva, [[...($this->actuales)($reserva)[0], $campo => $valor]], ['objetivo' => 'No debe guardarse'])
        ->assertSessionHasErrors([
            'detalles.0.fecha' => 'Cambiaste el espacio, la fecha o el horario: se requieren 10 días de anticipación; elige una fecha a partir del 28/11/2026.',
        ]);

    expect(($this->huella)($reserva))->toBe($antes);
})->with([
    'fecha' => ['fecha', '2026-11-26'],
    'espacio' => ['espacio_id', 'anexo'],
    'hora de inicio' => ['hora_inicio', '08:30'],
    'hora de fin' => ['hora_fin', '11:00'],
]);

test('a changed block that complies on the day of the change is accepted and resent', function () {
    $reserva = ($this->enCorreccion)();

    ($this->editar)($this->docente, $reserva, [[...($this->actuales)($reserva)[0], 'fecha' => '2026-11-28', 'hora_inicio' => '09:00']])
        ->assertSessionHasNoErrors();
    ($this->enviar)($this->docente, $reserva)->assertSessionHasNoErrors();

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and($reserva->fecha_entrega_formato?->toDateString())->toBe('2026-11-10');
});

test('a new block after the first send must comply: 9 days rejected, 10 accepted', function () {
    $reserva = ($this->enCorreccion)();
    $antes = ($this->huella)($reserva);
    $original = ($this->actuales)($reserva)[0];

    ($this->editar)($this->docente, $reserva, [$original, ($this->bloque)('2026-11-27', $this->anexo)])
        ->assertSessionHasErrors(['detalles.1.fecha' => 'Espacio nuevo: se requieren 10 días de anticipación; elige una fecha a partir del 28/11/2026.'])
        ->assertSessionDoesntHaveErrors('detalles.0.fecha');
    expect(($this->huella)($reserva))->toBe($antes);

    ($this->editar)($this->docente, $reserva, [$original, ($this->bloque)('2026-11-28', $this->anexo)])->assertSessionHasNoErrors();
    expect($reserva->detalles()->count())->toBe(2);
});

test('a block deleted and created again is treated as new', function () {
    $reserva = ($this->enCorreccion)();
    $antes = ($this->huella)($reserva);
    $sinId = ($this->actuales)($reserva)[0];
    unset($sinId['id']);

    ($this->editar)($this->docente, $reserva, [$sinId])
        ->assertSessionHasErrors(['detalles.0.fecha' => 'Espacio nuevo: se requieren 10 días de anticipación; elige una fecha a partir del 28/11/2026.']);

    expect(($this->huella)($reserva))->toBe($antes);
});

test('save and send under correction with an invalid change saves nothing', function () {
    $reserva = ($this->enCorreccion)();
    $antes = ($this->huella)($reserva);

    ($this->editar)($this->docente, $reserva, [[...($this->actuales)($reserva)[0], 'hora_fin' => '12:00']], ['enviar' => true])
        ->assertSessionHasErrors('detalles.0.fecha');

    expect(($this->huella)($reserva))->toBe($antes);
});

test('a past date cannot be resent and nothing is recorded', function () {
    $reserva = ($this->enCorreccion)();
    $this->travelTo(Date::parse('2026-11-26 08:00:00'));
    $antes = ($this->huella)($reserva);

    ($this->enviar)($this->docente, $reserva)->assertSessionHasErrors([
        'reserva.0' => 'Espacio 1 (Auditorio 1, 25/11/2026): la fecha ya pasó; cámbiala por una a partir del 06/12/2026.',
    ]);

    expect(($this->huella)($reserva))->toBe($antes);
});

test('a stale form cannot sneak back a date that is no longer saved', function () {
    $reserva = ($this->enCorreccion)();
    $viejo = ($this->actuales)($reserva)[0];

    // Primera pestaña: mueve el bloque a una fecha válida.
    ($this->editar)($this->docente, $reserva, [[...$viejo, 'fecha' => '2026-12-02']])->assertSessionHasNoErrors();

    // Segunda pestaña, con los datos de antes: para la base es un cambio y debe cumplir el plazo.
    ($this->editar)($this->docente, $reserva, [$viejo])->assertSessionHasErrors('detalles.0.fecha');

    expect($reserva->detalles()->value('fecha')->toDateString())->toBe('2026-12-02');
});

// --- Coordinación ---

test('a coordinator editing a pending request must respect the rule only for changed blocks', function () {
    $reserva = ($this->crear)($this->docente, [($this->bloque)('2026-11-25')]);
    ($this->enviar)($this->docente, $reserva)->assertSessionHasNoErrors();
    $this->travelTo(Date::parse('2026-11-18 10:00:00'));
    $antes = ($this->huella)($reserva);
    $original = ($this->actuales)($reserva)[0];

    ($this->editar)($this->coordinador, $reserva, [[...$original, 'hora_inicio' => '07:00']])->assertSessionHasErrors('detalles.0.fecha');
    expect(($this->huella)($reserva))->toBe($antes); // sin «editada_coordinador»

    ($this->editar)($this->coordinador, $reserva, [[...$original, 'cantidad_alumnos' => 30]])->assertSessionHasNoErrors();
    expect(HistorialReserva::query()->where('reserva_id', $reserva->id)->orderByDesc('id')->value('accion'))->toBe(HistorialReserva::EDITADA_COORDINADOR);

    // Aprobar no aplica la regla: la fecha original (a 7 días) sigue siendo válida.
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN);
});

test('a coordinator own request returned by administration keeps its unchanged dates', function () {
    $reserva = ($this->crear)($this->coordinador, [($this->bloque)('2026-11-25')]);
    ($this->enviar)($this->coordinador, $reserva)->assertSessionHasNoErrors();
    $this->workflow->ejecutar($reserva, $this->admin, W::SOLICITAR_CORRECCION, 'Revisa el horario');
    $this->travelTo(Date::parse('2026-11-18 10:00:00'));

    ($this->editar)($this->coordinador, $reserva, [[...($this->actuales)($reserva)[0], 'hora_fin' => '11:00']])
        ->assertSessionHasErrors('detalles.0.fecha');

    ($this->enviar)($this->coordinador, $reserva)->assertSessionHasNoErrors();
    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($reserva->fecha_entrega_formato?->toDateString())->toBe('2026-11-10');
});

// --- Reservas históricas ---

test('historical reservations are neither invalidated nor blocked', function () {
    // Autorizada con fecha ya pasada: se sigue consultando igual.
    $autorizada = Reserva::factory()->enEstado(E::AUTORIZADA)
        ->conDetalle(['espacio_id' => $this->auditorio->id, 'fecha' => '2026-11-05', 'estado_detalle' => ReservaDetalle::AUTORIZADA])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $this->actingAs($this->docente)->get(route('reservas.show', $autorizada))->assertOk();
    expect($autorizada->refresh()->estado_id)->toBe(E::AUTORIZADA);

    // Pendiente de administración a 3 días: autorizar no aplica la regla.
    $pendiente = Reserva::factory()->enEstado(E::PENDIENTE_ADMIN)
        ->conDetalle(['espacio_id' => $this->anexo->id, 'fecha' => '2026-11-13'])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id, 'actividad_id' => $this->actividad->id]);
    $this->workflow->ejecutar($pendiente, $this->admin, W::AUTORIZAR, 'Autorizada');
    expect($pendiente->refresh()->estado_id)->toBe(E::AUTORIZADA);

    // En corrección desde antes de la fecha de entrega (sin fecha_entrega_formato): cuenta como
    // ya enviada, así que su fecha original a 4 días se conserva al reenviar.
    $antigua = Reserva::factory()->enEstado(E::CORRECCION)
        ->conDetalle(['espacio_id' => $this->auditorio->id, 'fecha' => '2026-11-14'])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    expect($antigua->fecha_entrega_formato)->toBeNull();

    ($this->enviar)($this->docente, $antigua)->assertSessionHasNoErrors();
    expect($antigua->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});
