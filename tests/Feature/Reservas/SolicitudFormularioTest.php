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
 * Módulo de solicitudes: docente responsable y requerimientos por bloque
 * (etapa 1: validación al guardar y persistencia).
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->otraLicenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->companero = User::factory()->rol('docente')->create();
    $this->companero->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->actividad = Actividad::factory()->create();
    $this->espacio = Espacio::factory()->create(['capacidad' => 40]);

    $this->proyector = Requerimiento::create(['nombre' => 'Proyector', 'estatus' => 'activo']);
    $this->bocina = Requerimiento::create(['nombre' => 'Bocina', 'estatus' => 'activo']);
    $this->espacio->requerimientos()->attach([
        $this->proyector->id => ['cantidad_disponible' => 2],
        $this->bocina->id => ['cantidad_disponible' => 1],
    ]);

    $this->solicitud = fn (array $detalle = [], array $cambios = []): array => [
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
});

test('a draft stores the responsible teacher and the requirements of each block', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->solicitud)([
            'docente_responsable_id' => $this->companero->id,
            'requerimientos' => [
                ['requerimiento_id' => $this->proyector->id, 'cantidad' => 2],
                ['requerimiento_id' => $this->bocina->id, 'cantidad' => 1],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $detalle = Reserva::firstOrFail()->detalles()->firstOrFail();

    expect($detalle->docente_responsable_id)->toBe($this->companero->id)
        ->and($detalle->requerimientos()->pluck('reserva_detalle_requerimientos.cantidad', 'requerimientos.id')->map(fn ($c) => (int) $c)->all())
        ->toEqual([$this->proyector->id => 2, $this->bocina->id => 1]);
});

test('an incomplete draft without teacher or requirements is still saved', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->solicitud)(['cantidad_alumnos' => null, 'requerimientos' => []]))
        ->assertSessionHasNoErrors();

    $detalle = Reserva::firstOrFail()->detalles()->firstOrFail();

    expect(Reserva::firstOrFail()->estado_id)->toBe(E::BORRADOR)
        ->and($detalle->docente_responsable_id)->toBeNull()
        ->and($detalle->requerimientos()->count())->toBe(0);
});

test('the responsible teacher must be an active teacher or coordinator of the program', function (string $caso) {
    $responsableId = 999999;

    if ($caso !== 'inexistente') {
        $responsable = match ($caso) {
            'otra licenciatura' => User::factory()->rol('docente')->create(),
            'inactivo' => User::factory()->rol('docente')->inactivo()->create(),
            'administrador' => User::factory()->rol('admin')->create(),
        };
        $responsable->licenciaturas()->attach($caso === 'otra licenciatura' ? $this->otraLicenciatura : $this->licenciatura);
        $responsableId = $responsable->id;
    }

    $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->solicitud)(['docente_responsable_id' => $responsableId]))
        ->assertSessionHasErrors('detalles.0.docente_responsable_id');

    expect(Reserva::count())->toBe(0);
})->with(['otra licenciatura', 'inactivo', 'administrador', 'inexistente']);

test('a coordinator of the program can be the responsible teacher', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->solicitud)(['docente_responsable_id' => $this->coordinador->id]))
        ->assertSessionHasNoErrors();

    expect(Reserva::firstOrFail()->detalles()->value('docente_responsable_id'))->toBe($this->coordinador->id);
});

test('only active requirements of the space and within the available quantity are accepted', function (string $caso, string $mensaje) {
    $requerimiento = match ($caso) {
        'ajeno' => Requerimiento::create(['nombre' => 'Microscopio', 'estatus' => 'activo']),
        'eliminado' => tap(Requerimiento::create(['nombre' => 'Pizarrón', 'estatus' => 'eliminado']), fn ($r) => $this->espacio->requerimientos()->attach($r->id, ['cantidad_disponible' => 5])),
        'excede' => $this->proyector,
    };

    $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->solicitud)([
            'requerimientos' => [['requerimiento_id' => $requerimiento->id, 'cantidad' => $caso === 'excede' ? 3 : 1]],
        ]))
        ->assertSessionHasErrors(['detalles.0.requerimientos.0' => $mensaje]);

    expect(Reserva::count())->toBe(0);
})->with([
    ['ajeno', 'El requerimiento seleccionado no está disponible en este espacio.'],
    ['eliminado', 'El requerimiento «Pizarrón» ya no está vigente.'],
    ['excede', 'Solo hay 2 de «Proyector» en este espacio.'],
]);

test('a requirement cannot be repeated nor requested with quantity zero', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), ($this->solicitud)([
            'requerimientos' => [
                ['requerimiento_id' => $this->proyector->id, 'cantidad' => 1],
                ['requerimiento_id' => $this->proyector->id, 'cantidad' => 0],
            ],
        ]))
        ->assertSessionHasErrors(['detalles.0.requerimientos.0.requerimiento_id', 'detalles.0.requerimientos.1.cantidad']);
});

// Bloques de la misma solicitud (mismo espacio, otro horario) con los requerimientos indicados.
function bloquesConRequerimientos(array ...$porBloque): array
{
    return array_map(fn (array $requerimientos, int $i): array => [
        'espacio_id' => test()->espacio->id,
        'fecha' => Date::tomorrow()->toDateString(),
        'hora_inicio' => sprintf('%02d:00', 8 + $i * 2),
        'hora_fin' => sprintf('%02d:00', 9 + $i * 2),
        'cantidad_alumnos' => 20,
        'requerimientos' => $requerimientos,
    ], $porBloque, array_keys($porBloque));
}

test('the same requirement can be requested in different blocks', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), [...($this->solicitud)(), 'detalles' => bloquesConRequerimientos(
            [['requerimiento_id' => $this->proyector->id, 'cantidad' => 2], ['requerimiento_id' => $this->bocina->id, 'cantidad' => 1]],
            [['requerimiento_id' => $this->proyector->id, 'cantidad' => 2], ['requerimiento_id' => $this->bocina->id, 'cantidad' => 1]],
            [['requerimiento_id' => $this->proyector->id, 'cantidad' => 1]],
        )])
        ->assertSessionHasNoErrors();

    $detalles = Reserva::firstOrFail()->detalles()->with('requerimientos')->orderBy('id')->get();

    expect($detalles)->toHaveCount(3)
        ->and($detalles->map(fn ($d) => $d->requerimientos->pluck('pivot.cantidad', 'id')->map(fn ($c) => (int) $c)->sortKeys()->all())->all())
        ->toEqual([
            [$this->proyector->id => 2, $this->bocina->id => 1],
            [$this->proyector->id => 2, $this->bocina->id => 1],
            [$this->proyector->id => 1],
        ]);
});

test('a requirement repeated inside one block is rejected without flagging the other blocks', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), [...($this->solicitud)(), 'detalles' => bloquesConRequerimientos(
            [['requerimiento_id' => $this->proyector->id, 'cantidad' => 1], ['requerimiento_id' => $this->proyector->id, 'cantidad' => 1]],
            [['requerimiento_id' => $this->proyector->id, 'cantidad' => 1]],
        )])
        ->assertSessionHasErrors([
            'detalles.0.requerimientos.0.requerimiento_id' => 'El requerimiento está repetido.',
            'detalles.0.requerimientos.1.requerimiento_id' => 'El requerimiento está repetido.',
        ])
        ->assertSessionDoesntHaveErrors(['detalles.1.requerimientos.0.requerimiento_id', 'detalles.1.requerimientos.0']);

    expect(Reserva::count())->toBe(0);
});

test('each block must respect the quantity available in its space', function () {
    $this->actingAs($this->docente)
        ->post(route('reservas.store'), [...($this->solicitud)(), 'detalles' => bloquesConRequerimientos(
            [['requerimiento_id' => $this->proyector->id, 'cantidad' => 2]],
            [['requerimiento_id' => $this->proyector->id, 'cantidad' => 3]],
        )])
        ->assertSessionHasErrors(['detalles.1.requerimientos.0' => 'Solo hay 2 de «Proyector» en este espacio.'])
        ->assertSessionDoesntHaveErrors(['detalles.0.requerimientos.0']);

    expect(Reserva::count())->toBe(0);
});

test('editing replaces the requirements and omitting them keeps the saved ones', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->solicitud)([
        'requerimientos' => [['requerimiento_id' => $this->proyector->id, 'cantidad' => 1]],
    ]));
    $reserva = Reserva::firstOrFail();
    $detalle = $reserva->detalles()->firstOrFail();

    // Sin la clave `requerimientos`: se conservan.
    $this->actingAs($this->docente)
        ->put(route('reservas.update', $reserva), ($this->solicitud)(['id' => $detalle->id]))
        ->assertSessionHasNoErrors();
    expect($detalle->requerimientos()->count())->toBe(1);

    // Con la clave: se sustituyen.
    $this->actingAs($this->docente)
        ->put(route('reservas.update', $reserva), ($this->solicitud)([
            'id' => $detalle->id,
            'requerimientos' => [['requerimiento_id' => $this->bocina->id, 'cantidad' => 1]],
        ]))
        ->assertSessionHasNoErrors();
    expect($detalle->requerimientos()->pluck('requerimientos.id')->all())->toBe([$this->bocina->id]);

    // Lista vacía: se quitan todos.
    $this->actingAs($this->docente)
        ->put(route('reservas.update', $reserva), ($this->solicitud)(['id' => $detalle->id, 'requerimientos' => []]))
        ->assertSessionHasNoErrors();
    expect($detalle->requerimientos()->count())->toBe(0);
});

test('a coordinator changing requirements is recorded, saving the same ones is not', function () {
    $reserva = Reserva::factory()->conDetalle(['espacio_id' => $this->espacio->id])->enEstado(E::PENDIENTE_COORDINADOR)
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id, 'actividad_id' => $this->actividad->id]);
    $detalle = $reserva->detalles()->firstOrFail();
    $datos = fn (array $requerimientos) => ($this->solicitud)([
        'id' => $detalle->id,
        'fecha' => $detalle->fecha->toDateString(),
        'requerimientos' => $requerimientos,
    ], ['nombre_actividad' => $reserva->nombre_actividad, 'objetivo' => $reserva->objetivo]);
    $ediciones = fn () => $reserva->historial()->where('accion', HistorialReserva::EDITADA_COORDINADOR)->count();

    $this->actingAs($this->coordinador)
        ->put(route('reservas.update', $reserva), $datos([['requerimiento_id' => $this->proyector->id, 'cantidad' => 2]]))
        ->assertSessionHasNoErrors();
    expect($ediciones())->toBe(1)
        ->and($reserva->historial()->where('accion', HistorialReserva::EDITADA_COORDINADOR)->value('comentario'))->toContain('espacios y horarios');

    $this->actingAs($this->coordinador)
        ->put(route('reservas.update', $reserva), $datos([['requerimiento_id' => $this->proyector->id, 'cantidad' => 2]]))
        ->assertSessionHasNoErrors();
    expect($ediciones())->toBe(1);
});

test('the form offers teachers of own programs only, without private data, and the active requirements of each space', function () {
    $ajeno = User::factory()->rol('docente')->create();
    $ajeno->licenciaturas()->attach($this->otraLicenciatura);
    $inactivo = User::factory()->rol('docente')->inactivo()->create();
    $inactivo->licenciaturas()->attach($this->licenciatura);
    $eliminado = Requerimiento::create(['nombre' => 'Pizarrón', 'estatus' => 'eliminado']);
    $this->espacio->requerimientos()->attach($eliminado->id, ['cantidad_disponible' => 3]);

    $this->actingAs($this->docente)
        ->get(route('reservas.create'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($ajeno, $inactivo) {
            $page->component('reservas/Form');
            $docentes = collect($page->toArray()['props']['opciones']['docentes']);
            $espacio = collect($page->toArray()['props']['opciones']['espacios'])->firstWhere('id', $this->espacio->id);

            expect($docentes->pluck('id')->all())
                ->toContain($this->docente->id, $this->companero->id, $this->coordinador->id)
                ->not->toContain($ajeno->id, $inactivo->id)
                ->and(array_keys($docentes->first()))->toBe(['id', 'nombre', 'licenciatura_ids'])
                ->and($docentes->firstWhere('id', $this->companero->id)['licenciatura_ids'])->toBe([$this->licenciatura->id])
                ->and(collect($espacio['requerimientos'])->pluck('nombre')->all())->toBe(['Bocina', 'Proyector'])
                ->and(collect($espacio['requerimientos'])->firstWhere('nombre', 'Proyector')['cantidad_disponible'])->toBe(2);
        });
});

test('the edit and detail pages include the teacher and the requirements of each block', function () {
    $this->actingAs($this->docente)->post(route('reservas.store'), ($this->solicitud)([
        'docente_responsable_id' => $this->companero->id,
        'requerimientos' => [['requerimiento_id' => $this->proyector->id, 'cantidad' => 2]],
    ]));
    $reserva = Reserva::firstOrFail();

    $this->actingAs($this->docente)->get(route('reservas.edit', $reserva))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reserva.detalles.0.docente_responsable_id', $this->companero->id)
            ->where('reserva.detalles.0.requerimientos', [['requerimiento_id' => $this->proyector->id, 'cantidad' => 2]]));

    $this->actingAs($this->docente)->get(route('reservas.show', $reserva))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reserva.detalles.0.docente_responsable', $this->companero->name)
            ->where('reserva.detalles.0.requerimientos_vista', [['nombre' => 'Proyector', 'cantidad' => 2]]));
});
