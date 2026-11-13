<?php

use App\Models\Actividad;
use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Columna ACCIONES del listado de solicitudes: cada rol ve solo los botones que la política y
 * el estado actual le permiten (lo calcula el servidor), y Laravel vuelve a autorizar al ejecutar.
 */
beforeEach(function () {
    $this->travelTo(Date::parse('2026-11-12 09:00:00'));

    $this->lsc = Licenciatura::factory()->create(['nombre' => 'Sistemas']);
    $this->otra = Licenciatura::factory()->create(['nombre' => 'Derecho']);
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->lsc->id);
    $this->otroDocente = User::factory()->rol('docente')->create();
    $this->otroDocente->licenciaturas()->attach($this->lsc->id);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->lsc->id);
    $this->coordinadorAjeno = User::factory()->rol('coordinador')->create();
    $this->coordinadorAjeno->licenciaturas()->attach($this->otra->id);
    $this->admin = User::factory()->rol('admin')->create();
    $this->superadmin = User::factory()->rol('superadmin')->create();
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40]);
    $this->workflow = app(W::class);

    $this->crear = function (?User $autor = null, string $fecha = '2026-12-15'): Reserva {
        $this->actingAs($autor ?? $this->docente)->post(route('reservas.store'), [
            'licenciatura_id' => $this->lsc->id, 'actividad_id' => $this->actividad->id,
            'nombre_actividad' => 'Jornada', 'objetivo' => 'Objetivo de prueba',
            'detalles' => [['espacio_id' => $this->espacio->id, 'fecha' => $fecha, 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20]],
        ])->assertSessionHasNoErrors();

        return Reserva::query()->latest('id')->firstOrFail();
    };
    $this->enviada = function (): Reserva {
        $r = ($this->crear)();
        $this->workflow->ejecutar($r, $this->docente, W::ENVIAR);

        return $r->refresh();
    };
    $this->enAdmin = function (): Reserva {
        $r = ($this->enviada)();
        $this->workflow->ejecutar($r, $this->coordinador, W::APROBAR);

        return $r->refresh();
    };

    // Acciones de la fila de una reserva en el listado de quien consulta (null si no aparece).
    $this->acciones = function (User $usuario, Reserva $reserva): ?array {
        $fila = null;
        $this->actingAs($usuario)->get(route('solicitudes.index'))->assertOk()
            ->assertInertia(function (Assert $page) use (&$fila, $reserva) {
                $page->component('solicitudes/Index');
                $fila = collect($page->toArray()['props']['solicitudes'])->firstWhere('id', $reserva->id);
            });

        return $fila['acciones'] ?? null;
    };
    $this->claves = fn (?array $acciones) => $acciones === null ? null : collect($acciones['transiciones'])->pluck('accion')->all();
});

// --- Docente ---

test('the teacher sees VIEW and EDIT on her own draft and no workflow buttons', function () {
    $r = ($this->crear)();
    $a = ($this->acciones)($this->docente, $r);

    expect($a['ver'])->toBeTrue()->and($a['editar'])->toBeTrue()->and($a['transiciones'])->toBe([]);
});

test('once sent, the teacher only sees VIEW', function () {
    $r = ($this->enviada)();
    $a = ($this->acciones)($this->docente, $r);

    expect($a['ver'])->toBeTrue()->and($a['editar'])->toBeFalse()->and($a['transiciones'])->toBe([]);
});

test('in correction the teacher can edit again', function () {
    $r = ($this->enviada)();
    $this->workflow->ejecutar($r, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el programa');

    expect(($this->acciones)($this->docente, $r->refresh())['editar'])->toBeTrue();
});

test('a teacher does not see other people\'s requests nor can she edit them', function () {
    $r = ($this->crear)();

    expect(($this->acciones)($this->otroDocente, $r))->toBeNull();
    $this->actingAs($this->otroDocente)->get(route('reservas.edit', $r))->assertForbidden();
});

// --- Coordinación ---

test('coordination sees VIEW, EDIT and SEND TO DIRECTOR on pending requests of its program', function () {
    $r = ($this->enviada)();
    $a = ($this->acciones)($this->coordinador, $r);

    expect($a['ver'])->toBeTrue()
        ->and($a['editar'])->toBeTrue()
        ->and($a['transiciones'])->toBe([['accion' => W::APROBAR, 'etiqueta' => 'Enviar a directivo', 'requiereObservacion' => false]]);
});

test('returning for correction is not offered in the coordination list (it stays in the detail)', function () {
    $r = ($this->enviada)();

    expect(($this->claves)(($this->acciones)($this->coordinador, $r)))->not->toContain(W::SOLICITAR_CORRECCION);

    $this->actingAs($this->coordinador)->get(route('reservas.show', $r))
        ->assertInertia(fn (Assert $page) => $page->where('acciones', fn ($acciones) => collect($acciones)->pluck('accion')->contains(W::SOLICITAR_CORRECCION)));
});

test('after sending to the director, coordination only sees VIEW', function () {
    $r = ($this->enAdmin)();
    $a = ($this->acciones)($this->coordinador, $r);

    expect($a['editar'])->toBeFalse()->and($a['transiciones'])->toBe([]);
});

test('a coordinator of another program does not see the request', function () {
    expect(($this->acciones)($this->coordinadorAjeno, ($this->enviada)()))->toBeNull();
});

test('SEND TO DIRECTOR from the list uses the existing approval transition', function () {
    $r = ($this->enviada)();

    $this->actingAs($this->coordinador)->post(route('reservas.transicion', $r), ['accion' => W::APROBAR])
        ->assertRedirect(route('reservas.show', $r));

    expect($r->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($r->historial()->pluck('accion')->all())->toContain('aprobada_coordinador');
});

// --- Directivo (administración) ---

test('the director sees VIEW, AUTHORIZE, REJECT and SEND BACK on pending requests, without EDIT', function () {
    $r = ($this->enAdmin)();

    foreach ([$this->admin, $this->superadmin] as $directivo) {
        $a = ($this->acciones)($directivo, $r);

        expect($a['ver'])->toBeTrue()
            ->and($a['editar'])->toBeFalse()
            ->and(collect($a['transiciones'])->pluck('etiqueta', 'accion')->all())->toBe([
                W::SOLICITAR_CORRECCION => 'Mandar a corregir',
                W::AUTORIZAR => 'Autorizar',
                W::DENEGAR => 'Rechazar',
            ])
            ->and(collect($a['transiciones'])->every(fn ($t) => $t['requiereObservacion'] === true))->toBeTrue();
    }
});

test('the director gets no decision buttons on requests that are not pending for him', function () {
    $pendienteCoord = ($this->enviada)();
    $autorizada = ($this->enAdmin)();
    $this->workflow->ejecutar($autorizada, $this->admin, W::AUTORIZAR, 'Listo');

    expect(($this->acciones)($this->admin, $pendienteCoord)['transiciones'])->toBe([])
        ->and(($this->acciones)($this->admin, $autorizada->refresh())['transiciones'])->toBe([]);
});

test('the director does not see other people\'s drafts', function () {
    expect(($this->acciones)($this->admin, ($this->crear)()))->toBeNull();
});

test('a decision without an observation is rejected and changes nothing', function () {
    $r = ($this->enAdmin)();
    $historial = $r->historial()->count();

    $this->actingAs($this->admin)->post(route('reservas.transicion', $r), ['accion' => W::AUTORIZAR, 'observacion' => ''])
        ->assertSessionHasErrors('observacion');

    expect($r->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and($r->historial()->count())->toBe($historial);
});

// --- Seguridad en el servidor (aunque Vue oculte el botón) ---

test('hidden actions are still rejected by Laravel without changing data', function () {
    $r = ($this->enAdmin)();
    $antes = [$r->refresh()->getAttributes(), $r->historial()->count(), Notificacion::query()->count()];

    foreach ([[$this->docente, W::AUTORIZAR], [$this->coordinador, W::DENEGAR], [$this->coordinadorAjeno, W::SOLICITAR_CORRECCION]] as [$usuario, $accion]) {
        $this->actingAs($usuario)->post(route('reservas.transicion', $r), ['accion' => $accion, 'observacion' => 'Intento'])->assertForbidden();
    }

    expect([$r->refresh()->getAttributes(), $r->historial()->count(), Notificacion::query()->count()])->toBe($antes);
});

// --- Impresión: solo en el detalle ---

test('the list rows carry no print action; printing stays available from the detail', function () {
    $r = ($this->enAdmin)();

    expect(array_keys(($this->acciones)($this->coordinador, $r)))->toBe(['ver', 'editar', 'transiciones']);

    foreach ([$this->docente, $this->coordinador, $this->admin] as $usuario) {
        $this->actingAs($usuario)->get(route('reservas.imprimir', $r))->assertOk();
    }
    $this->actingAs($this->coordinadorAjeno)->get(route('reservas.imprimir', $r))->assertForbidden();
});

// --- Anticipación: docentes y coordinadores siguen sujetos a los 10 días ---

test('teachers and coordinators are still bound by the 10-day rule', function () {
    $borrador = ($this->crear)(fecha: '2026-11-17');
    $this->actingAs($this->docente)->post(route('reservas.transicion', $borrador), ['accion' => W::ENVIAR])
        ->assertSessionHasErrors('reserva.0');
    expect($borrador->refresh()->estado_id)->toBe(E::BORRADOR);

    $r = ($this->enviada)();
    $detalle = $r->detalles()->firstOrFail();
    $this->actingAs($this->coordinador)->put(route('reservas.update', $r), [
        'licenciatura_id' => $this->lsc->id, 'actividad_id' => $this->actividad->id, 'objetivo' => 'Objetivo de prueba',
        'detalles' => [['id' => $detalle->id, 'espacio_id' => $this->espacio->id, 'fecha' => '2026-11-17', 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'cantidad_alumnos' => 20]],
    ])->assertSessionHasErrors('detalles.0.fecha');
    expect($detalle->refresh()->fecha->toDateString())->toBe('2026-12-15');
});
