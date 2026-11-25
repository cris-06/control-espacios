<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Requerimiento;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use App\Services\RecurrenciaReserva;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * «Esta reserva se repite»: cada repetición es un bloque normal de reserva_detalles (con
 * detalle_origen_id y ocurrencia), así que pasa por las mismas validaciones, choques,
 * anticipación y autorización que cualquier bloque. La cantidad son las ocasiones ADICIONALES y
 * la fecha de fin (incluida) tiene prioridad.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lic = Licenciatura::factory()->create(['siglas' => 'LSC']);
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->lic->id);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->lic->id);
    $this->admin = User::factory()->rol('admin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['nombre' => 'Auditorio', 'capacidad' => 40, 'minimo_alumnos' => 1, 'maximo_alumnos' => 40]);
    $this->sala = Espacio::factory()->create(['nombre' => 'Sala 2', 'capacidad' => 20, 'minimo_alumnos' => 1, 'maximo_alumnos' => 20]);
    $this->proyector = Requerimiento::create(['nombre' => 'Proyector', 'estatus' => 'activo']);
    $this->espacio->requerimientos()->attach($this->proyector->id, ['cantidad_disponible' => 2]);
    $this->recurrencia = app(RecurrenciaReserva::class);
    $this->workflow = app(W::class);

    $this->bloque = fn (array $extra = []): array => [
        'espacio_id' => $this->espacio->id, 'fecha' => '2026-12-01', 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
        'cantidad_alumnos' => 30, ...$extra,
    ];
    $this->datos = fn (array $extra = []): array => [
        'licenciatura_id' => $this->lic->id, 'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Taller semanal', 'objetivo' => 'Practicar',
        'detalles' => [($this->bloque)()],
        ...$extra,
    ];
    $this->semanal = fn (array $extra = []): array => ['repite' => true, 'repeticion_frecuencia' => 'semanal', 'repeticion_cantidad' => 4, ...$extra];
    $this->crear = function (array $extra = []): Reserva {
        $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)($extra))
            ->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };
    $this->fechas = fn (Reserva $r): array => $r->detalles()->get()->map(fn (ReservaDetalle $d) => $d->fecha->toDateString())->all();
});

// --- Algoritmo de fechas ---

test('the count is the number of additional dates for every frequency', function () {
    expect($this->recurrencia->fechas('2026-12-01', 'diaria', 3, null))->toBe(['2026-12-02', '2026-12-03', '2026-12-04'])
        ->and($this->recurrencia->fechas('2026-12-01', 'semanal', 4, null))->toBe(['2026-12-08', '2026-12-15', '2026-12-22', '2026-12-29'])
        ->and($this->recurrencia->fechas('2026-12-01', 'mensual', 2, null))->toBe(['2027-01-01', '2027-02-01'])
        ->and($this->recurrencia->fechas('2026-12-01', 'anual', 2, null))->toBe(['2027-12-01', '2028-12-01'])
        ->and($this->recurrencia->fechas('2026-12-01', 'semanal', null, null))->toBe([])
        ->and($this->recurrencia->fechas('2026-12-01', 'semanal', 0, null))->toBe([]);
});

test('monthly and yearly dates never overflow the month and are computed from the original', function () {
    // 31 ene: 28 feb (2027 no es bisiesto), 31 mar, 30 abr, 31 may; no se arrastra el 28.
    expect($this->recurrencia->fechas('2027-01-31', 'mensual', 4, null))->toBe(['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'])
        // Año bisiesto: 31 ene 2028 → 29 feb 2028.
        ->and($this->recurrencia->fechas('2028-01-31', 'mensual', 1, null))->toBe(['2028-02-29'])
        // 29 feb: 28 feb los años no bisiestos y otra vez 29 feb en 2032.
        ->and($this->recurrencia->fechas('2028-02-29', 'anual', 4, null))->toBe(['2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'])
        // Fin de año.
        ->and($this->recurrencia->fechas('2026-12-30', 'diaria', 3, null))->toBe(['2026-12-31', '2027-01-01', '2027-01-02']);
});

test('the end date has priority over the count and is inclusive', function () {
    expect($this->recurrencia->fechas('2026-12-01', 'semanal', 1, '2026-12-29'))->toBe(['2026-12-08', '2026-12-15', '2026-12-22', '2026-12-29'])
        // El 28 no coincide con el patrón: termina el 22.
        ->and($this->recurrencia->fechas('2026-12-01', 'semanal', null, '2026-12-28'))->toBe(['2026-12-08', '2026-12-15', '2026-12-22'])
        ->and($this->recurrencia->fechas('2026-12-01', 'diaria', null, '2026-12-03'))->toBe(['2026-12-02', '2026-12-03']);
});

test('the generator never returns more than the limit plus one', function () {
    expect($this->recurrencia->fechas('2026-12-01', 'diaria', null, '2027-12-01'))->toHaveCount(RecurrenciaReserva::MAX_REPETICIONES + 1)
        ->and($this->recurrencia->fechas('2026-12-01', 'diaria', 1000, null))->toHaveCount(RecurrenciaReserva::MAX_REPETICIONES + 1);
});

// --- Guardado ---

test('without repetition nothing changes', function () {
    $reserva = ($this->crear)();

    expect($reserva->repeticion_frecuencia)->toBeNull()
        ->and($reserva->detalles()->count())->toBe(1)
        ->and($reserva->detalles()->value('ocurrencia'))->toBeNull()
        ->and($reserva->detalles()->value('detalle_origen_id'))->toBeNull();

    // Una casilla sin marcar no exige nada aunque lleguen otros campos.
    $otra = ($this->crear)(['repite' => false, 'repeticion_frecuencia' => 'semanal']);
    expect($otra->repeticion_frecuencia)->toBeNull()->and($otra->detalles()->count())->toBe(1);
});

test('a weekly repetition x4 from December 1st creates five dates that copy the whole block', function () {
    $reserva = ($this->crear)([
        ...($this->semanal)(),
        'detalles' => [($this->bloque)([
            'material_requerido' => 'Mesa', 'observaciones' => 'Puerta norte',
            'requerimientos' => [['requerimiento_id' => $this->proyector->id, 'cantidad' => 2]],
        ])],
    ]);

    $detalles = $reserva->detalles()->get();
    $original = $detalles->firstWhere('ocurrencia', 0);

    expect(($this->fechas)($reserva))->toBe(['2026-12-01', '2026-12-08', '2026-12-15', '2026-12-22', '2026-12-29'])
        ->and($reserva->repeticion_frecuencia)->toBe('semanal')
        ->and($reserva->repeticion_cantidad)->toBe(4)
        ->and($reserva->repeticion_hasta)->toBeNull()
        ->and($original->detalle_origen_id)->toBeNull()
        ->and($detalles->where('detalle_origen_id', $original->id)->pluck('ocurrencia')->sort()->values()->all())->toBe([1, 2, 3, 4]);

    foreach ($detalles as $d) {
        expect($d->espacio_id)->toBe($this->espacio->id)
            ->and(substr((string) $d->hora_inicio, 0, 5))->toBe('10:00')
            ->and(substr((string) $d->hora_fin, 0, 5))->toBe('12:00')
            ->and($d->cantidad_alumnos)->toBe(30)
            ->and($d->material_requerido)->toBe('Mesa')
            ->and($d->observaciones)->toBe('Puerta norte')
            ->and($d->requerimientos()->pluck('reserva_detalle_requerimientos.cantidad', 'requerimientos.id')->map(fn ($c) => (int) $c)->all())
            ->toBe([$this->proyector->id => 2]);
    }

    // Folios intactos.
    expect($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and($reserva->folio_institucional)->toStartWith('LSC-');
});

test('with an end date the count is ignored and the end date is included', function () {
    $reserva = ($this->crear)(($this->semanal)(['repeticion_cantidad' => 1, 'repeticion_hasta' => '2026-12-22']));

    expect(($this->fechas)($reserva))->toBe(['2026-12-01', '2026-12-08', '2026-12-15', '2026-12-22'])
        ->and($reserva->repeticion_cantidad)->toBeNull()
        ->and($reserva->repeticion_hasta->toDateString())->toBe('2026-12-22');
});

test('daily, monthly and yearly repetitions are saved', function (string $frecuencia, array $esperadas) {
    $reserva = ($this->crear)(['repite' => true, 'repeticion_frecuencia' => $frecuencia, 'repeticion_cantidad' => 2]);

    expect(($this->fechas)($reserva))->toBe($esperadas);
})->with([
    'diaria' => ['diaria', ['2026-12-01', '2026-12-02', '2026-12-03']],
    'mensual' => ['mensual', ['2026-12-01', '2027-01-01', '2027-02-01']],
    'anual' => ['anual', ['2026-12-01', '2027-12-01', '2028-12-01']],
]);

test('several blocks keep their date differences', function () {
    $reserva = ($this->crear)([
        ...($this->semanal)(['repeticion_cantidad' => 2]),
        'detalles' => [
            ($this->bloque)(),
            ($this->bloque)(['espacio_id' => $this->sala->id, 'fecha' => '2026-12-03', 'hora_inicio' => '16:00', 'hora_fin' => '18:00', 'cantidad_alumnos' => 15]),
        ],
    ]);

    $porEspacio = $reserva->detalles()->get()->groupBy('espacio_id')
        ->map(fn ($g) => $g->map(fn ($d) => $d->fecha->toDateString())->values()->all());

    expect($porEspacio[$this->espacio->id])->toBe(['2026-12-01', '2026-12-08', '2026-12-15'])
        ->and($porEspacio[$this->sala->id])->toBe(['2026-12-03', '2026-12-10', '2026-12-17'])
        ->and($reserva->detalles()->where('espacio_id', $this->sala->id)->pluck('cantidad_alumnos')->unique()->all())->toBe([15]);
});

test('invalid repetition data is rejected and nothing is saved', function () {
    $post = fn (array $extra) => $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)($extra));

    $post(['repite' => true])->assertSessionHasErrors('repeticion_frecuencia');
    $post(['repite' => true, 'repeticion_frecuencia' => 'quincenal', 'repeticion_cantidad' => 2])->assertSessionHasErrors('repeticion_frecuencia');
    $post(['repite' => true, 'repeticion_frecuencia' => 'semanal'])
        ->assertSessionHasErrors(['repeticion_cantidad' => 'Indica el número de repeticiones o la fecha de fin.']);
    $post(($this->semanal)(['repeticion_cantidad' => 0]))->assertSessionHasErrors('repeticion_cantidad');
    $post(($this->semanal)(['repeticion_cantidad' => -3]))->assertSessionHasErrors('repeticion_cantidad');
    $post(($this->semanal)(['repeticion_cantidad' => 2.5]))->assertSessionHasErrors('repeticion_cantidad');
    $post(($this->semanal)(['repeticion_cantidad' => 53]))->assertSessionHasErrors('repeticion_cantidad');
    $post(($this->semanal)(['repeticion_cantidad' => null, 'repeticion_hasta' => '2026-12-01']))
        ->assertSessionHasErrors(['repeticion_hasta' => 'La fecha de fin debe ser posterior a la primera fecha de la actividad.']);
    $post(($this->semanal)(['repeticion_cantidad' => null, 'repeticion_hasta' => '2026-12-05']))
        ->assertSessionHasErrors(['repeticion_hasta' => 'Con esa fecha de fin no se genera ninguna repetición.']);
    $post(($this->semanal)(['repeticion_cantidad' => null, 'repeticion_hasta' => '2027-12-05']))
        ->assertSessionHasErrors(['repeticion_hasta' => 'La fecha de fin puede ser a lo más un año después de la primera fecha.']);
    $post(['repite' => true, 'repeticion_frecuencia' => 'diaria', 'repeticion_hasta' => '2027-03-01'])
        ->assertSessionHasErrors(['repeticion_hasta' => 'Con esa fecha de fin habría más de 52 repeticiones por espacio. Elige una fecha más cercana o una frecuencia menor.']);
    $post(['repite' => true, 'repeticion_frecuencia' => 'semanal', 'repeticion_hasta' => '1-12-2026'])->assertSessionHasErrors('repeticion_hasta');

    expect(Reserva::query()->count())->toBe(0)->and(ReservaDetalle::query()->count())->toBe(0);
});

test('a request cannot generate more than 100 activities in total', function () {
    $bloques = array_map(fn (int $h) => ($this->bloque)(['hora_inicio' => sprintf('%02d:00', 7 + $h), 'hora_fin' => sprintf('%02d:30', 7 + $h), 'cantidad_alumnos' => 5]), range(0, 1));

    // 2 bloques × (1 + 49) = 100: se permite.
    ($this->crear)([...($this->semanal)(['repeticion_cantidad' => 49]), 'detalles' => $bloques]);
    expect(ReservaDetalle::query()->count())->toBe(100);

    // 2 × (1 + 50) = 102: se rechaza.
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([...($this->semanal)(['repeticion_cantidad' => 50]), 'detalles' => $bloques]))
        ->assertSessionHasErrors(['repeticion_cantidad' => 'Se generarían 102 actividades; el máximo por solicitud es 100. Reduce las repeticiones o los espacios.']);
    expect(Reserva::query()->count())->toBe(1);
});

test('every generated date gets the normal validations of the block', function () {
    // Más alumnos que la capacidad: lo detecta la validación del bloque, que se copia a cada fecha.
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([
        ...($this->semanal)(),
        'detalles' => [($this->bloque)(['cantidad_alumnos' => 60])],
    ]))->assertSessionHasErrors('detalles.0.cantidad_alumnos');

    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([
        ...($this->semanal)(),
        'detalles' => [($this->bloque)(['hora_inicio' => '12:00', 'hora_fin' => '10:00'])],
    ]))->assertSessionHasErrors('detalles.0.hora_fin');

    expect(ReservaDetalle::query()->count())->toBe(0);
});

// --- Edición ---

test('the edit form recovers the configuration and the generated dates', function () {
    $reserva = ($this->crear)(($this->semanal)());

    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reserva.repeticion', ['frecuencia' => 'semanal', 'cantidad' => 4, 'hasta' => null])
            // Solo el bloque capturado; las fechas generadas van aparte.
            ->has('reserva.detalles', 1)
            ->where('reserva.detalles.0.fecha', '2026-12-01')
            ->where('reserva.detalles.0.fechas_repeticion', ['2026-12-08', '2026-12-15', '2026-12-22', '2026-12-29']));
});

test('editing keeps the same rows, without duplicates, and turning it off removes the repetitions', function () {
    $reserva = ($this->crear)(($this->semanal)());
    $ids = $reserva->detalles()->pluck('id')->sort()->values()->all();
    $base = $reserva->detalles()->where('ocurrencia', 0)->value('id');

    // Guardar otra vez lo mismo: mismas filas.
    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)([
        ...($this->semanal)(), 'detalles' => [($this->bloque)(['id' => $base])],
    ]))->assertSessionHasNoErrors();
    expect($reserva->detalles()->pluck('id')->sort()->values()->all())->toBe($ids);

    // Cambiar el horario y bajar a 2 repeticiones: se actualizan las que quedan y se borran las demás.
    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)([
        ...($this->semanal)(['repeticion_cantidad' => 2]), 'detalles' => [($this->bloque)(['id' => $base, 'hora_inicio' => '13:00', 'hora_fin' => '14:00'])],
    ]))->assertSessionHasNoErrors();
    expect(($this->fechas)($reserva))->toBe(['2026-12-01', '2026-12-08', '2026-12-15'])
        ->and($reserva->detalles()->pluck('id')->sort()->values()->all())->toBe(array_slice($ids, 0, 3))
        ->and($reserva->detalles()->pluck('hora_inicio')->map(fn ($h) => substr((string) $h, 0, 5))->unique()->all())->toBe(['13:00']);

    // Sin los campos de repetición (cliente anterior) se conserva la configuración.
    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)([
        'detalles' => [($this->bloque)(['id' => $base, 'hora_inicio' => '13:00', 'hora_fin' => '14:00'])],
    ]))->assertSessionHasNoErrors();
    expect($reserva->detalles()->count())->toBe(3)->and($reserva->refresh()->repeticion_cantidad)->toBe(2);

    // Desmarcar «Esta reserva se repite»: queda solo el bloque original.
    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), ($this->datos)([
        'repite' => false, 'detalles' => [($this->bloque)(['id' => $base])],
    ]))->assertSessionHasNoErrors();
    $reserva->refresh();
    expect($reserva->detalles()->pluck('id')->all())->toBe([$base])
        ->and($reserva->detalles()->value('ocurrencia'))->toBeNull()
        ->and($reserva->repeticion_frecuencia)->toBeNull()
        ->and($reserva->repeticion_cantidad)->toBeNull();
});

test('a coordinator edit of the repetition is recorded in the history', function () {
    $reserva = ($this->crear)(($this->semanal)());
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $base = $reserva->detalles()->where('ocurrencia', 0)->value('id');

    $this->actingAs($this->coordinador)->put(route('reservas.update', $reserva->refresh()), ($this->datos)([
        ...($this->semanal)(['repeticion_cantidad' => 1]), 'detalles' => [($this->bloque)(['id' => $base])],
    ]))->assertSessionHasNoErrors();

    $reserva->refresh();
    expect($reserva->historial()->where('accion', HistorialReserva::EDITADA_COORDINADOR)->value('comentario'))->toContain('repetición')
        ->and($reserva->detalles()->count())->toBe(2)
        ->and($reserva->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id));
});

// --- Flujo: disponibilidad, choques y autorización atómica ---

test('a repeated date that overlaps another block of the request is warned on save', function () {
    $respuesta = $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)([
        'repite' => true, 'repeticion_frecuencia' => 'diaria', 'repeticion_cantidad' => 1,
        'detalles' => [($this->bloque)(), ($this->bloque)(['fecha' => '2026-12-02', 'cantidad_alumnos' => 10])],
    ]));

    $respuesta->assertSessionHasNoErrors();
    $toast = $respuesta->baseResponse->getSession()->get('inertia.flash_data')['toast'] ?? [];
    expect($toast['type'] ?? null)->toBe('warning')
        ->and($toast['message'] ?? '')->toContain('se traslapan');
});

test('a repeated date that hits an authorized reservation blocks the whole authorization', function () {
    // Reserva ajena autorizada el 15 de diciembre (tercera fecha de la serie) a la misma hora.
    $ajena = Reserva::factory()->enEstado(E::AUTORIZADA)->conDetalle([
        'espacio_id' => $this->espacio->id, 'fecha' => '2026-12-15', 'hora_inicio' => '11:00:00', 'hora_fin' => '13:00:00',
        'estado_detalle' => ReservaDetalle::AUTORIZADA,
    ])->create(['licenciatura_id' => $this->lic->id]);

    $reserva = ($this->crear)(($this->semanal)());
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->coordinador, W::APROBAR);

    try {
        $this->workflow->ejecutar($reserva->refresh(), $this->admin, W::AUTORIZAR, 'Autorizada');
        $this->fail('La autorización debió rechazarse por la fecha repetida.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain($ajena->folio);
    }

    // Todo o nada: ninguna fecha quedó autorizada.
    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($reserva->detalles()->where('estado_detalle', ReservaDetalle::AUTORIZADA)->count())->toBe(0)
        ->and($reserva->historial()->where('accion', HistorialReserva::AUTORIZADA)->count())->toBe(0);
});

test('a free series is authorized as a whole and keeps its folios', function () {
    $reserva = ($this->crear)(($this->semanal)());
    $folios = [$reserva->folio, $reserva->folio_institucional];

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->coordinador, W::APROBAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->admin, W::AUTORIZAR, 'Autorizada');

    $reserva->refresh();
    expect($reserva->estado_id)->toBe(E::AUTORIZADA)
        ->and($reserva->detalles()->where('estado_detalle', ReservaDetalle::AUTORIZADA)->count())->toBe(5)
        ->and([$reserva->folio, $reserva->folio_institucional])->toBe($folios);

    // Otra solicitud que cae en una fecha generada ya no puede autorizarse.
    $otra = ($this->crear)(['detalles' => [($this->bloque)(['fecha' => '2026-12-29'])]]);
    $this->workflow->ejecutar($otra, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($otra->refresh(), $this->coordinador, W::APROBAR);
    expect(fn () => $this->workflow->ejecutar($otra->refresh(), $this->admin, W::AUTORIZAR, 'Autorizada'))
        ->toThrow(ValidationException::class);
});

test('rejecting and sending back for correction keep the series intact', function () {
    $reserva = ($this->crear)(($this->semanal)());
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->coordinador, W::SOLICITAR_CORRECCION, 'Revisa el objetivo');

    expect($reserva->refresh()->detalles()->count())->toBe(5)
        ->and($reserva->repeticion_frecuencia)->toBe('semanal');

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->coordinador, W::APROBAR);
    $this->workflow->ejecutar($reserva->refresh(), $this->admin, W::DENEGAR, 'Sin cupo');

    expect($reserva->refresh()->estado_id)->toBe(E::DENEGADA)
        ->and($reserva->detalles()->count())->toBe(5);
});

test('the printable Formato 10 lists every date and explains the repetition', function () {
    $reserva = ($this->crear)(($this->semanal)(['repeticion_cantidad' => 2]));

    $this->actingAs($this->docente)->get(route('reservas.imprimir', $reserva))->assertOk()
        ->assertSee('Se repite cada semana: la fecha original y 2 repeticiones.')
        ->assertSee('01-12-2026')
        ->assertSee('08-12-2026')
        ->assertSee('15-12-2026');

    $conFin = ($this->crear)(($this->semanal)(['repeticion_cantidad' => null, 'repeticion_hasta' => '2026-12-15']));
    $this->actingAs($this->docente)->get(route('reservas.imprimir', $conFin))->assertOk()
        ->assertSee('Se repite cada semana hasta el 15-12-2026.');
});
