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
use App\Services\ReservaWorkflowService as W;
use Illuminate\Validation\ValidationException;

/**
 * El envío del docente a coordinación (desde borrador o tras una corrección) admite
 * solicitudes incompletas, pero rechaza requerimientos que el catálogo ya no respalda.
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);

    $this->espacio = Espacio::factory()->create(['nombre' => 'Laboratorio B10']);
    $this->equipo = Requerimiento::create(['nombre' => 'Equipo B10', 'estatus' => 'activo']);
    $this->espacio->requerimientos()->attach($this->equipo->id, ['cantidad_disponible' => 10]);

    // Reserva del docente con un bloque que pide 8 unidades (válido mientras haya 10).
    $this->conEquipo = function (int $estado, int $cantidad = 8): Reserva {
        $reserva = Reserva::factory()->enEstado($estado)
            ->conDetalle(['espacio_id' => $this->espacio->id])
            ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
        $reserva->detalles()->firstOrFail()->requerimientos()->attach($this->equipo->id, ['cantidad' => $cantidad]);

        return $reserva;
    };

    // Intenta enviar y devuelve los mensajes del rechazo.
    $this->rechazo = function (Reserva $reserva): array {
        try {
            app(W::class)->ejecutar($reserva, $this->docente, W::ENVIAR);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->all();
        }

        throw new RuntimeException('El envío debió rechazarse.');
    };

    // Foto de lo que un rechazo no debe tocar.
    $this->foto = fn (Reserva $reserva): array => [
        'reserva' => Reserva::query()->findOrFail($reserva->id)->only(['estado_id', 'coordinador_id', 'observaciones_admin']),
        'detalles' => ReservaDetalle::query()->where('reserva_id', $reserva->id)->orderBy('id')->get()->toArray(),
        'pedidos' => $reserva->detalles()->firstOrFail()->requerimientosPedidos()->orderBy('id')->get(['id', 'requerimiento_id', 'cantidad'])->toArray(),
        'historial' => HistorialReserva::query()->count(),
        'notificaciones' => Notificacion::query()->count(),
    ];
});

test('resending after a correction is rejected when the quantity exceeds the current availability', function () {
    $reserva = ($this->conEquipo)(E::CORRECCION);
    $this->espacio->requerimientos()->updateExistingPivot($this->equipo->id, ['cantidad_disponible' => 6]);

    expect(($this->rechazo)($reserva))->toBe(['Espacio 1: solo hay 6 de «Equipo B10» en este espacio.']);
});

test('sending is rejected when a requested requirement was deleted', function (int $estado) {
    $reserva = ($this->conEquipo)($estado);
    $this->equipo->update(['estatus' => 'eliminado']);

    expect(($this->rechazo)($reserva))->toBe(['Espacio 1: el requerimiento «Equipo B10» ya no está vigente.']);
})->with([
    'from a draft' => [E::BORRADOR],
    'after a correction' => [E::CORRECCION],
]);

test('sending is rejected when a requested requirement was withdrawn from the space', function () {
    $reserva = ($this->conEquipo)(E::CORRECCION);
    $this->espacio->requerimientos()->detach($this->equipo->id);

    expect(($this->rechazo)($reserva))->toBe(['Espacio 1: el requerimiento seleccionado no está disponible en este espacio.']);
});

test('a rejected send keeps the state, the blocks, the quantities, the history and the notifications', function () {
    $reserva = ($this->conEquipo)(E::CORRECCION);
    $this->espacio->requerimientos()->updateExistingPivot($this->equipo->id, ['cantidad_disponible' => 6]);
    $antes = ($this->foto)($reserva);

    ($this->rechazo)($reserva);

    expect(($this->foto)($reserva))->toBe($antes)
        ->and($antes['reserva']['estado_id'])->toBe(E::CORRECCION)
        ->and($antes['pedidos'][0]['cantidad'])->toBe(8);
});

test('resending with valid requirements goes to the coordinator', function () {
    $reserva = ($this->conEquipo)(E::CORRECCION, cantidad: 6);
    $this->espacio->requerimientos()->updateExistingPivot($this->equipo->id, ['cantidad_disponible' => 6]);

    app(W::class)->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and($reserva->historial()->latest('id')->value('accion'))->toBe(HistorialReserva::ENVIADA_COORDINADOR);
});

test('an incomplete draft without requirements can still be sent', function () {
    $reserva = Reserva::factory()->enEstado(E::BORRADOR)
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);
    $reserva->forceFill(['nombre_actividad' => null, 'objetivo' => ''])->save();

    app(W::class)->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR);
});

test('the detail page shows why the resend was rejected', function () {
    $reserva = ($this->conEquipo)(E::CORRECCION);
    $this->espacio->requerimientos()->updateExistingPivot($this->equipo->id, ['cantidad_disponible' => 6]);

    $this->actingAs($this->docente)
        ->from(route('reservas.show', $reserva))
        ->post(route('reservas.transicion', $reserva), ['accion' => W::ENVIAR])
        ->assertRedirect(route('reservas.show', $reserva))
        ->assertSessionHasErrors(['reserva.0' => 'Espacio 1: solo hay 6 de «Equipo B10» en este espacio.']);

    expect($reserva->refresh()->estado_id)->toBe(E::CORRECCION);
});
