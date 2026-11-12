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
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Formato 10, etapa 1: la fecha de elaboración (fecha_creacion) la fija el servidor al crear;
 * la fecha de entrega (fecha_entrega_formato) se registra una sola vez en el PRIMER envío.
 * Ninguna se puede escribir desde el formulario. Las casillas de «Autorizaciones» salen del
 * estado y del historial, y nunca muestran una aprobación anterior a una corrección.
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

    $this->espacio = Espacio::factory()->create(['nombre' => 'Auditorio 1', 'capacidad' => 40]);
    $this->aula = Espacio::factory()->create(['nombre' => 'Anexo', 'capacidad' => 40]);
    $this->sillas = Requerimiento::create(['nombre' => 'Sillas', 'estatus' => 'activo']);
    $this->manteles = Requerimiento::create(['nombre' => 'Manteles', 'estatus' => 'activo']);
    $this->espacio->requerimientos()->attach($this->sillas->id, ['cantidad_disponible' => 50]);
    $this->espacio->requerimientos()->attach($this->manteles->id, ['cantidad_disponible' => 10]);
    $this->aula->requerimientos()->attach($this->sillas->id, ['cantidad_disponible' => 30]);

    // Datos del formulario, opcionalmente con campos que el cliente NO debe poder fijar.
    $this->datos = fn (array $extra = []): array => [
        'licenciatura_id' => $this->licenciatura->id,
        'actividad_id' => $this->actividad->id,
        'nombre_actividad' => 'Jornada Académica',
        'objetivo' => 'Brindar un espacio académico interdisciplinario',
        'detalles' => [[
            'espacio_id' => $this->espacio->id,
            'fecha' => '2026-12-01',
            'hora_inicio' => '08:00',
            'hora_fin' => '13:30',
            'cantidad_alumnos' => 30,
            'requerimientos' => [['requerimiento_id' => $this->sillas->id, 'cantidad' => 10]],
        ]],
        ...$extra,
    ];

    $this->manipulados = [
        'fecha_creacion' => '2020-01-01 00:00:00',
        'fecha_entrega_formato' => '2020-01-02',
        'folio' => 'FALSO-001',
        'estado_id' => E::AUTORIZADA,
    ];

    // Crea la reserva por la ruta normal (como lo haría el formulario).
    $this->crear = function (User $quien, array $extra = []): Reserva {
        $this->actingAs($quien)->post(route('reservas.store'), ($this->datos)($extra))->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };

    $this->nueva = fn (int $estado, ?User $creador = null): Reserva => Reserva::factory()->enEstado($estado)
        ->conDetalle(['espacio_id' => $this->espacio->id])
        ->create(['usuario_creador_id' => ($creador ?? $this->docente)->id, 'licenciatura_id' => $this->licenciatura->id]);

    $this->props = function (Reserva $reserva, User $usuario, string $ruta = 'reservas.show'): array {
        $props = [];

        $this->actingAs($usuario)->get(route($ruta, $reserva))
            ->assertOk()
            ->assertInertia(function (Assert $pagina) use (&$props) {
                $props = $pagina->toArray()['props'];
            });

        return $props;
    };

    // Casillas de autorización indexadas por clave, con solo lo esencial para comparar.
    $this->casillas = function (Reserva $reserva, User $usuario): array {
        return collect(($this->props)($reserva, $usuario)['autorizaciones'])
            ->mapWithKeys(fn (array $c): array => [$c['clave'] => [$c['situacion'], $c['etiqueta'], $c['nombre']]])
            ->all();
    };

    $this->fechas = fn (Reserva $reserva): array => [
        Reserva::query()->findOrFail($reserva->id)->fecha_creacion?->toDateTimeString(),
        Reserva::query()->findOrFail($reserva->id)->fecha_entrega_formato?->toDateString(),
    ];
});

// --- Fecha de elaboración ---

test('the creation date is set by the server and RES folio is kept', function () {
    $reserva = ($this->crear)($this->docente);

    expect(($this->fechas)($reserva))->toBe(['2026-11-10 09:30:00', null])
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and(($this->props)($reserva, $this->docente)['reserva'])
        ->toMatchArray(['folio' => Reserva::folioPara($reserva->id), 'fecha_entrega_formato' => null])
        ->and(Date::parse(($this->props)($reserva, $this->docente)['reserva']['fecha_creacion'])->toDateTimeString())->toBe('2026-11-10 09:30:00');
});

test('dates, folio and state sent by the client are ignored when creating', function () {
    $reserva = ($this->crear)($this->docente, $this->manipulados);

    expect(($this->fechas)($reserva))->toBe(['2026-11-10 09:30:00', null])
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and($reserva->estado_id)->toBe(E::BORRADOR);
});

test('editing never changes the automatic dates, even with manipulated values', function (string $metodo) {
    $reserva = ($this->crear)($this->docente);
    $this->travelTo(Date::parse('2026-11-12 16:00:00'));

    $this->actingAs($this->docente)
        ->{$metodo}(route('reservas.update', $reserva), ($this->datos)([...$this->manipulados, 'objetivo' => 'Objetivo corregido']))
        ->assertSessionHasNoErrors();

    $reserva->refresh();
    expect(($this->fechas)($reserva))->toBe(['2026-11-10 09:30:00', null])
        ->and($reserva->objetivo)->toBe('Objetivo corregido')
        ->and($reserva->folio)->toBe(Reserva::folioPara($reserva->id))
        ->and($reserva->estado_id)->toBe(E::BORRADOR);
})->with(['PUT' => ['put'], 'PATCH' => ['patch']]);

test('the event dates remain editable', function () {
    $reserva = ($this->crear)($this->docente);
    $datos = ($this->datos)();
    $datos['detalles'][0] = [...$datos['detalles'][0], 'id' => $reserva->detalles()->value('id'), 'fecha' => '2026-12-05', 'hora_inicio' => '09:00', 'hora_fin' => '11:00'];

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), $datos)->assertSessionHasNoErrors();

    $detalle = $reserva->detalles()->firstOrFail();
    expect([$detalle->fecha->toDateString(), ReservaDetalle::hhmm($detalle->hora_inicio), ReservaDetalle::hhmm($detalle->hora_fin)])
        ->toBe(['2026-12-05', '09:00', '11:00']);
});

// --- Fecha de entrega ---

test('a draft that was never sent keeps the delivery date empty', function () {
    $reserva = ($this->crear)($this->docente);

    expect(($this->fechas)($reserva)[1])->toBeNull()
        ->and(($this->props)($reserva, $this->docente, 'reservas.edit')['reserva']['fecha_entrega_formato'])->toBeNull();
});

test('the first send from a teacher to coordination sets the delivery date', function () {
    $reserva = ($this->crear)($this->docente);
    $this->travelTo(Date::parse('2026-11-11 10:00:00'));

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and(($this->fechas)($reserva))->toBe(['2026-11-10 09:30:00', '2026-11-11']);
});

test('save and send from the form also sets the delivery date', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->datos)(['enviar' => true]))->assertSessionHasNoErrors();
    $reserva = Reserva::query()->latest('id')->firstOrFail();

    expect($reserva->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and(($this->fechas)($reserva))->toBe(['2026-11-10 09:30:00', '2026-11-10']);
});

test('a returned request keeps its original delivery date when resent', function (string $quienCorrige) {
    $reserva = ($this->crear)($this->docente);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    $this->travelTo(Date::parse('2026-11-13 12:00:00'));
    if ($quienCorrige === 'admin') {
        $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
        $this->workflow->ejecutar($reserva, $this->admin, W::SOLICITAR_CORRECCION, 'Ajusta el horario');
    } else {
        $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Ajusta el horario');
    }
    expect($reserva->refresh()->estado_id)->toBe(E::CORRECCION);

    $this->travelTo(Date::parse('2026-11-20 08:00:00'));
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and(($this->fechas)($reserva))->toBe(['2026-11-10 09:30:00', '2026-11-10']);
})->with(['coordination' => ['coordinador'], 'administration' => ['admin']]);

test('a rejected send leaves the delivery date and everything else untouched', function () {
    $reserva = ($this->crear)($this->docente);
    $this->espacio->requerimientos()->updateExistingPivot($this->sillas->id, ['cantidad_disponible' => 5]);
    $antes = [Reserva::query()->findOrFail($reserva->id)->getAttributes(), HistorialReserva::query()->count(), Notificacion::query()->count()];

    expect(fn () => $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR))->toThrow(ValidationException::class);

    expect([Reserva::query()->findOrFail($reserva->id)->getAttributes(), HistorialReserva::query()->count(), Notificacion::query()->count()])->toBe($antes)
        ->and(($this->fechas)($reserva)[1])->toBeNull();
});

test('a rejected save and send keeps the draft saved without a delivery date', function () {
    // Se guarda válido y después el catálogo baja a 5 sillas (se pidieron 10).
    $reserva = ($this->crear)($this->docente);
    $this->espacio->requerimientos()->updateExistingPivot($this->sillas->id, ['cantidad_disponible' => 5]);
    $datos = ($this->datos)(['enviar' => true]);
    $datos['detalles'][0]['id'] = $reserva->detalles()->value('id');
    // Sin la clave `requerimientos` se conservan los ya guardados (el guardado sí pasa).
    unset($datos['detalles'][0]['requerimientos']);

    $this->actingAs($this->docente)->put(route('reservas.update', $reserva), $datos)->assertRedirect(route('reservas.show', $reserva));

    expect($reserva->refresh()->estado_id)->toBe(E::BORRADOR)
        ->and(($this->fechas)($reserva)[1])->toBeNull();
});

test('a coordinator sending their own request to administration sets the delivery date once', function () {
    $reserva = ($this->crear)($this->coordinador);
    $this->travelTo(Date::parse('2026-11-11 10:00:00'));

    $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR);
    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and(($this->fechas)($reserva)[1])->toBe('2026-11-11');

    $this->travelTo(Date::parse('2026-11-14 10:00:00'));
    $this->workflow->ejecutar($reserva, $this->admin, W::SOLICITAR_CORRECCION, 'Falta el material');
    $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR);

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and(($this->fechas)($reserva))->toBe(['2026-11-10 09:30:00', '2026-11-11']);
});

test('a coordinator strict send that is rejected leaves the delivery date empty', function () {
    $reserva = Reserva::factory()->create(['usuario_creador_id' => $this->coordinador->id, 'licenciatura_id' => $this->licenciatura->id]);

    // Sin espacios: el envío directo a administración es estricto y se rechaza.
    expect(fn () => $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR))->toThrow(ValidationException::class);

    expect($reserva->refresh()->estado_id)->toBe(E::BORRADOR)
        ->and(($this->fechas)($reserva)[1])->toBeNull();
});

test('review, authorization and denial never change the delivery date', function (string $final) {
    $reserva = ($this->crear)($this->docente);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    $this->travelTo(Date::parse('2026-11-15 10:00:00'));
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    $this->workflow->ejecutar($reserva, $this->admin, $final, 'Decisión de administración');

    expect(($this->fechas)($reserva))->toBe(['2026-11-10 09:30:00', '2026-11-10']);
})->with(['authorize' => [W::AUTORIZAR], 'deny' => [W::DENEGAR]]);

// --- Autorizaciones (solo lectura, desde el historial) ---

test('the authorization boxes follow the real state and history', function () {
    $reserva = ($this->crear)($this->docente);
    expect(($this->casillas)($reserva, $this->docente))->toBe([
        'docente' => ['pendiente', 'En elaboración, sin enviar', $this->docente->name],
        'coordinacion' => ['pendiente', 'Pendiente', $this->coordinador->name],
        'administracion' => ['pendiente', 'Pendiente', null],
    ]);

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    expect(($this->casillas)($reserva, $this->coordinador))->toBe([
        'docente' => ['completada', 'Entregó la solicitud', $this->docente->name],
        'coordinacion' => ['pendiente', 'En revisión', $this->coordinador->name],
        'administracion' => ['pendiente', 'Pendiente', null],
    ]);

    $this->travelTo(Date::parse('2026-11-11 11:00:00'));
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Autorizada.');

    $casillas = collect(($this->props)($reserva, $this->docente)['autorizaciones'])->keyBy('clave');
    $aprobacion = $reserva->historial()->where('accion', HistorialReserva::APROBADA_COORDINADOR)->firstOrFail();
    $autorizacion = $reserva->historial()->where('accion', HistorialReserva::AUTORIZADA)->firstOrFail();

    expect($casillas['coordinacion'])->toBe([
        'clave' => 'coordinacion', 'titulo' => 'Coordinación', 'situacion' => 'completada',
        'etiqueta' => 'Aprobada por coordinación', 'nombre' => $this->coordinador->name,
        'rol' => 'Coordinación', 'fecha' => $aprobacion->fecha->toIso8601String(),
    ])->and($casillas['administracion'])->toBe([
        'clave' => 'administracion', 'titulo' => 'Autorización', 'situacion' => 'completada',
        'etiqueta' => 'Autorizada', 'nombre' => $this->admin->name,
        'rol' => 'Administración', 'fecha' => $autorizacion->fecha->toIso8601String(),
    ]);
});

test('an approval made before a correction is never shown as current', function () {
    $reserva = ($this->crear)($this->docente);
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));
    $this->workflow->ejecutar($reserva, $this->admin, W::SOLICITAR_CORRECCION, 'Cambia el espacio');

    expect(($this->casillas)($reserva, $this->docente))->toBe([
        'docente' => ['pendiente', 'Debe atender la corrección y reenviar', $this->docente->name],
        'coordinacion' => ['pendiente', 'Pendiente', $this->coordinador->name],
        'administracion' => ['devuelta', 'Solicitó corrección', $this->admin->name],
    ]);

    // Al reenviar, coordinación vuelve a revisar: la aprobación vieja sigue sin contar.
    $this->travelTo(Date::parse('2026-11-13 09:00:00'));
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect(($this->casillas)($reserva, $this->coordinador)['coordinacion'])->toBe(['pendiente', 'En revisión', $this->coordinador->name])
        ->and(($this->casillas)($reserva, $this->coordinador)['administracion'])->toBe(['pendiente', 'Pendiente', null]);
});

test('a correction requested by coordination is shown in the coordination box', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el objetivo');

    expect(($this->casillas)($reserva, $this->docente)['coordinacion'])->toBe(['devuelta', 'Solicitó corrección', $this->coordinador->name]);
});

test('a coordinator own request and a denial are shown from the history', function () {
    $reserva = ($this->crear)($this->coordinador);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->admin, W::DENEGAR, 'Sin espacio disponible');

    expect(($this->casillas)($reserva, $this->coordinador))->toBe([
        'docente' => ['completada', 'Entregó la solicitud', $this->coordinador->name],
        'coordinacion' => ['completada', 'Solicitud propia enviada a administración', $this->coordinador->name],
        'administracion' => ['denegada', 'Denegada', $this->admin->name],
    ]);
});

test('the edit page shows the same read-only boxes and nobody else can see them', function () {
    $reserva = ($this->crear)($this->docente);
    $otraDocente = User::factory()->rol('docente')->create();
    $otraDocente->licenciaturas()->attach($this->licenciatura);

    $props = ($this->props)($reserva, $this->docente, 'reservas.edit');
    expect(collect($props['autorizaciones'])->pluck('clave')->all())->toBe(['docente', 'coordinacion', 'administracion'])
        ->and(array_keys($props['autorizaciones'][0]))->toBe(['clave', 'titulo', 'situacion', 'etiqueta', 'nombre', 'rol', 'fecha']);

    // Un borrador ajeno no se ve ni se edita (las políticas no cambiaron).
    $this->actingAs($otraDocente)->get(route('reservas.show', $reserva))->assertForbidden();
    $this->actingAs($otraDocente)->get(route('reservas.edit', $reserva))->assertForbidden();
    $this->actingAs($this->admin)->get(route('reservas.show', $reserva))->assertForbidden();
});

// --- Integridad ---

test('a request with several spaces and requirements keeps its blocks after saving and sending', function () {
    $datos = ($this->datos)();
    $datos['detalles'][] = [
        'espacio_id' => $this->aula->id, 'fecha' => '2026-12-02', 'hora_inicio' => '08:00', 'hora_fin' => '12:30',
        'cantidad_alumnos' => 25, 'lugar_especifico' => 'Anexo, planta baja',
        'requerimientos' => [['requerimiento_id' => $this->sillas->id, 'cantidad' => 25]],
    ];
    $datos['detalles'][0]['requerimientos'][] = ['requerimiento_id' => $this->manteles->id, 'cantidad' => 3];
    $this->actingAs($this->docente)->post(route('reservas.store'), $datos)->assertSessionHasNoErrors();
    $reserva = Reserva::query()->latest('id')->firstOrFail();

    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    $bloques = collect(($this->props)($reserva, $this->docente)['reserva']['detalles'])
        ->map(fn (array $d): array => [$d['espacio']['nombre'], $d['fecha'], $d['lugar_especifico'], collect($d['requerimientos_vista'])->map(fn ($r) => "{$r['nombre']}:{$r['cantidad']}")->sort()->values()->all()])
        ->all();

    expect($bloques)->toBe([
        ['Auditorio 1', '2026-12-01', null, ['Manteles:3', 'Sillas:10']],
        ['Anexo', '2026-12-02', 'Anexo, planta baja', ['Sillas:25']],
    ])->and(($this->fechas)($reserva)[1])->toBe('2026-11-10');
});

test('viewing the detail and the edit form writes nothing', function () {
    $reserva = ($this->crear)($this->docente);
    $escrituras = [];
    DB::listen(function ($consulta) use (&$escrituras) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $consulta->sql)) {
            $escrituras[] = $consulta->sql;
        }
    });
    $antes = Reserva::query()->findOrFail($reserva->id)->getAttributes();

    ($this->props)($reserva, $this->docente);
    ($this->props)($reserva, $this->docente, 'reservas.edit');

    expect($escrituras)->toBe([])
        ->and(Reserva::query()->findOrFail($reserva->id)->getAttributes())->toBe($antes);
});

test('the form has no input for automatic dates nor manual signatures', function () {
    $formulario = (string) file_get_contents(resource_path('js/pages/reservas/Form.vue'));
    $autorizaciones = (string) file_get_contents(resource_path('js/components/AutorizacionesReserva.vue'));

    expect($formulario)->not->toContain('id="fecha_entrega_formato"')
        ->and($formulario)->not->toContain('v-model="form.fecha_entrega_formato"')
        ->and($formulario)->not->toContain('fecha_creacion:')
        ->and($formulario)->toContain('<BloqueReserva')
        ->and($formulario)->not->toContain('v-html')
        ->and($autorizaciones)->not->toContain('<input')
        ->and($autorizaciones)->not->toContain('v-model')
        ->and($autorizaciones)->not->toContain('v-html');
});
