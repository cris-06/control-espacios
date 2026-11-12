<?php

use App\Models\Espacio;
use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Requerimiento;
use App\Models\Reserva;
use App\Models\ReservaDetalle;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * El detalle avisa de antemano, sin escribir nada, de los requerimientos pedidos que el
 * catálogo actual ya no respalda; solo a quien puede actuar sobre la solicitud ahora.
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->admin = User::factory()->rol('admin')->create();

    $this->espacio = Espacio::factory()->create(['nombre' => 'Laboratorio B10']);
    $this->equipo = Requerimiento::create(['nombre' => 'Equipo B10', 'estatus' => 'activo']);
    $this->espacio->requerimientos()->attach($this->equipo->id, ['cantidad_disponible' => 10]);

    // Reserva de la docente con un bloque que pide 8 unidades (válido mientras haya 10).
    $this->conEquipo = function (int $estado): Reserva {
        $reserva = Reserva::factory()->enEstado($estado)
            ->conDetalle(['espacio_id' => $this->espacio->id])
            ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
        $reserva->detalles()->firstOrFail()->requerimientos()->attach($this->equipo->id, ['cantidad' => 8]);

        return $reserva;
    };

    $this->reducirA6 = fn () => $this->espacio->requerimientos()->updateExistingPivot($this->equipo->id, ['cantidad_disponible' => 6]);

    $this->usuarios = fn (string $quien): User => match ($quien) {
        'creadora' => $this->docente,
        'coordinador' => $this->coordinador,
        'admin' => $this->admin,
    };

    $this->advertencias = function (Reserva $reserva, User $usuario): array {
        $advertencias = [];

        $this->actingAs($usuario)->get(route('reservas.show', $reserva))
            ->assertOk()
            ->assertInertia(function (Assert $pagina) use (&$advertencias) {
                $advertencias = $pagina->toArray()['props']['advertencias'];
            });

        return $advertencias;
    };
});

test('a quantity above the current availability is warned in advance', function () {
    $reserva = ($this->conEquipo)(E::PENDIENTE_COORDINADOR);
    ($this->reducirA6)();

    expect(($this->advertencias)($reserva, $this->coordinador))->toBe([[
        'detalle_id' => $reserva->detalles()->value('id'),
        'bloque' => 1,
        'mensaje' => 'Espacio 1 (Laboratorio B10): solo hay 6 de «Equipo B10» en este espacio.',
    ]]);
});

test('a deleted requirement is warned in advance', function () {
    $reserva = ($this->conEquipo)(E::PENDIENTE_ADMIN);
    $this->equipo->update(['estatus' => 'eliminado']);

    expect(array_column(($this->advertencias)($reserva, $this->admin), 'mensaje'))
        ->toBe(['Espacio 1 (Laboratorio B10): el requerimiento «Equipo B10» ya no está vigente.']);
});

test('a requirement withdrawn from the space is warned in advance', function () {
    $reserva = ($this->conEquipo)(E::CORRECCION);
    $this->espacio->requerimientos()->detach($this->equipo->id);

    expect(array_column(($this->advertencias)($reserva, $this->docente), 'mensaje'))
        ->toBe(['Espacio 1 (Laboratorio B10): el requerimiento seleccionado no está disponible en este espacio.']);
});

test('there are no warnings while the requirements are valid', function (int $estado, string $quien) {
    $reserva = ($this->conEquipo)($estado);

    expect(($this->advertencias)($reserva, ($this->usuarios)($quien)))->toBe([]);
})->with([
    'creator, draft' => [E::BORRADOR, 'creadora'],
    'coordinator reviewing' => [E::PENDIENTE_COORDINADOR, 'coordinador'],
    'administration authorizing' => [E::PENDIENTE_ADMIN, 'admin'],
]);

test('whoever may act on the request now sees the warning', function (int $estado, string $quien) {
    $reserva = ($this->conEquipo)($estado);
    ($this->reducirA6)();

    expect(($this->advertencias)($reserva, ($this->usuarios)($quien)))->toHaveCount(1);
})->with([
    'creator, draft' => [E::BORRADOR, 'creadora'],
    'creator, correction' => [E::CORRECCION, 'creadora'],
    'coordinator reviewing' => [E::PENDIENTE_COORDINADOR, 'coordinador'],
    'administration authorizing' => [E::PENDIENTE_ADMIN, 'admin'],
]);

test('whoever may only view the request gets no warnings', function (int $estado, string $quien) {
    $reserva = ($this->conEquipo)($estado);
    ($this->reducirA6)();

    expect(($this->advertencias)($reserva, ($this->usuarios)($quien)))->toBe([]);
})->with([
    'creator, waiting for the coordinator' => [E::PENDIENTE_COORDINADOR, 'creadora'],
    'creator, waiting for administration' => [E::PENDIENTE_ADMIN, 'creadora'],
    'coordinator, already with administration' => [E::PENDIENTE_ADMIN, 'coordinador'],
    'administration, still with the coordinator' => [E::PENDIENTE_COORDINADOR, 'admin'],
    'administration, already authorized' => [E::AUTORIZADA, 'admin'],
]);

test('blocks are numbered in the order they are shown, by date and time', function () {
    $otro = Espacio::factory()->create(['nombre' => 'Aula B10']);
    $otro->requerimientos()->attach($this->equipo->id, ['cantidad_disponible' => 10]);

    // Se crea primero el bloque más tardío para que el orden de creación no coincida.
    $reserva = Reserva::factory()->enEstado(E::PENDIENTE_COORDINADOR)
        ->conDetalle(['espacio_id' => $this->espacio->id, 'fecha' => Date::today()->addDays(3)->toDateString()])
        ->conDetalle(['espacio_id' => $otro->id, 'fecha' => Date::tomorrow()->toDateString()])
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    [$tardio, $temprano] = $reserva->detalles()->reorder('id')->get()->all();
    $tardio->requerimientos()->attach($this->equipo->id, ['cantidad' => 8]);
    $temprano->requerimientos()->attach($this->equipo->id, ['cantidad' => 9]);
    ($this->reducirA6)();
    $otro->requerimientos()->updateExistingPivot($this->equipo->id, ['cantidad_disponible' => 5]);

    expect(($this->advertencias)($reserva, $this->coordinador))->toBe([
        ['detalle_id' => $temprano->id, 'bloque' => 1, 'mensaje' => 'Espacio 1 (Aula B10): solo hay 5 de «Equipo B10» en este espacio.'],
        ['detalle_id' => $tardio->id, 'bloque' => 2, 'mensaje' => 'Espacio 2 (Laboratorio B10): solo hay 6 de «Equipo B10» en este espacio.'],
    ]);
});

test('viewing the detail with warnings writes nothing', function () {
    $reserva = ($this->conEquipo)(E::CORRECCION);
    ($this->reducirA6)();
    $foto = fn (): array => [
        'reserva' => Reserva::query()->findOrFail($reserva->id)->only(['estado_id', 'coordinador_id', 'observaciones_admin']),
        'detalles' => ReservaDetalle::query()->where('reserva_id', $reserva->id)->orderBy('id')->get()->toArray(),
        'pedidos' => DB::table('reserva_detalle_requerimientos')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        'catalogo' => DB::table('espacio_requerimientos')->orderBy('id')->get()->map(fn ($fila) => (array) $fila)->all(),
        'historial' => HistorialReserva::query()->count(),
        'notificaciones' => Notificacion::query()->count(),
    ];
    $antes = $foto();

    $escrituras = [];
    DB::listen(function ($consulta) use (&$escrituras) {
        if (preg_match('/^\s*(insert|update|delete)\b.*\b(reservas|reserva_detalles|reserva_detalle_requerimientos|historial_reservas|notificaciones|espacio_requerimientos|requerimientos|espacios)\b/i', $consulta->sql)) {
            $escrituras[] = $consulta->sql;
        }
    });

    foreach ([$this->docente, $this->coordinador, $this->admin] as $usuario) {
        expect(($this->advertencias)($reserva, $usuario))->toBeArray();
    }

    expect($escrituras)->toBe([])
        ->and($foto())->toBe($antes);
});
