<?php

use App\Models\EstadoReserva as E;
use App\Models\HistorialReserva as H;
use App\Models\Licenciatura;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\User;
use App\Services\ReservaWorkflowService as W;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * El detalle muestra solo la corrección o decisión VIGENTE según el estado actual, con
 * su autor real, rol y fecha; lo ya atendido queda únicamente en la línea de tiempo.
 */
beforeEach(function () {
    $this->licenciatura = Licenciatura::factory()->create();
    $this->docente = User::factory()->rol('docente')->create();
    $this->docente->licenciaturas()->attach($this->licenciatura);
    $this->coordinador = User::factory()->rol('coordinador')->create();
    $this->coordinador->licenciaturas()->attach($this->licenciatura);
    $this->admin = User::factory()->rol('admin')->create();
    $this->workflow = app(W::class);

    $this->nueva = fn (int $estado): Reserva => Reserva::factory()->enEstado($estado)->conDetalle()
        ->create(['usuario_creador_id' => $this->docente->id, 'licenciatura_id' => $this->licenciatura->id]);

    $this->vigente = function (Reserva $reserva, ?User $usuario = null): ?array {
        $observacion = 'sin leer';

        $this->actingAs($usuario ?? $this->docente)->get(route('reservas.show', $reserva))
            ->assertOk()
            ->assertInertia(function (Assert $pagina) use (&$observacion) {
                $observacion = $pagina->toArray()['props']['observacionVigente'];
            });

        return $observacion;
    };

    $this->ultima = fn (Reserva $reserva, string $accion): H => $reserva->historial()->where('accion', $accion)->latest('id')->firstOrFail();
});

test('a pending correction shows its comment, author, role and date', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el material requerido');
    $registro = ($this->ultima)($reserva, H::CORRECCION_SOLICITADA);

    expect(($this->vigente)($reserva))->toBe([
        'tipo' => 'correccion_solicitada',
        'comentario' => 'Falta el material requerido',
        'autor' => $this->coordinador->name,
        'rol' => 'Coordinación',
        'fecha' => $registro->fecha->toIso8601String(),
    ]);
});

test('a correction already attended disappears from the box but stays in the timeline', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el material requerido');
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_COORDINADOR)
        ->and(($this->vigente)($reserva))->toBeNull()
        // Lo guardado no cambia: la observación sigue en la reserva y en el historial.
        ->and($reserva->observaciones_admin)->toBe('Falta el material requerido')
        ->and($reserva->historial()->where('accion', H::CORRECCION_SOLICITADA)->value('comentario'))->toBe('Falta el material requerido');
});

test('a request waiting for administration does not show an attended correction nor the approval comment', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el material requerido');
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR, 'Revisado por coordinación');

    expect($reserva->refresh()->estado_id)->toBe(E::PENDIENTE_ADMIN)
        ->and(($this->vigente)($reserva, $this->admin))->toBeNull()
        ->and(($this->vigente)($reserva, $this->docente))->toBeNull();
});

test('an authorization shows its comment and administration as the author', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el material requerido');
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::APROBAR);
    $this->workflow->ejecutar($reserva, $this->admin, W::AUTORIZAR, 'Autorizada para el evento');

    expect(($this->vigente)($reserva))->toBe([
        'tipo' => 'autorizada',
        'comentario' => 'Autorizada para el evento',
        'autor' => $this->admin->name,
        'rol' => 'Administración',
        'fecha' => ($this->ultima)($reserva, H::AUTORIZADA)->fecha->toIso8601String(),
    ]);
});

test('a denial shows its reason and administration as the author', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_ADMIN);
    $this->workflow->ejecutar($reserva, $this->admin, W::DENEGAR, "El espacio estará en mantenimiento.\nPropón otra fecha.");

    expect(($this->vigente)($reserva))->toMatchArray([
        'tipo' => 'denegada',
        'comentario' => "El espacio estará en mantenimiento.\nPropón otra fecha.",
        'autor' => $this->admin->name,
        'rol' => 'Administración',
    ]);
});

test('with several corrections the most recent one is shown, even with the same date', function () {
    Date::setTestNow('2026-11-20 10:00:00');
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Primera corrección');
    $this->workflow->ejecutar($reserva, $this->docente, W::ENVIAR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Segunda corrección');

    expect(($this->vigente)($reserva)['comentario'])->toBe('Segunda corrección');
    Date::setTestNow();
});

test('the author role tells coordination apart from administration', function (string $quien, int $estado, string $rol) {
    $reserva = ($this->nueva)($estado);
    $this->workflow->ejecutar($reserva, $this->{$quien}, W::SOLICITAR_CORRECCION, 'Ajusta el horario');

    expect(($this->vigente)($reserva))->toMatchArray(['autor' => $this->{$quien}->name, 'rol' => $rol]);
})->with([
    'requested by coordination' => ['coordinador', E::PENDIENTE_COORDINADOR, 'Coordinación'],
    'requested by administration' => ['admin', E::PENDIENTE_ADMIN, 'Administración'],
]);

test('a history entry without comment keeps it null instead of reusing an older observation', function () {
    $reserva = ($this->nueva)(E::CORRECCION);
    $reserva->forceFill(['observaciones_admin' => 'Observación de otra transición'])->save();
    $reserva->historial()->create(['usuario_id' => $this->coordinador->id, 'accion' => H::CORRECCION_SOLICITADA, 'comentario' => null]);

    expect(($this->vigente)($reserva))->toMatchArray(['tipo' => 'correccion_solicitada', 'comentario' => null, 'autor' => $this->coordinador->name]);
});

test('old records without history fall back to the stored observation without inventing author or date', function () {
    $reserva = ($this->nueva)(E::AUTORIZADA);
    $reserva->forceFill(['observaciones_admin' => 'Autorizada antes de existir el historial'])->save();

    expect(($this->vigente)($reserva))->toBe([
        'tipo' => 'autorizada',
        'comentario' => 'Autorizada antes de existir el historial',
        'autor' => null,
        'rol' => null,
        'fecha' => null,
    ]);

    $sinNada = ($this->nueva)(E::DENEGADA);
    expect(($this->vigente)($sinNada))->toBeNull();
});

test('requests without a decision in force have no observation', function (int $estado) {
    $reserva = ($this->nueva)($estado);
    $reserva->forceFill(['observaciones_admin' => 'Texto antiguo que ya no aplica'])->save();
    $reserva->historial()->create(['usuario_id' => $this->coordinador->id, 'accion' => H::APROBADA_COORDINADOR, 'comentario' => 'Comentario opcional']);

    expect(($this->vigente)($reserva))->toBeNull();
})->with([
    'draft' => [E::BORRADOR],
    'waiting for the coordinator' => [E::PENDIENTE_COORDINADOR],
    'waiting for administration' => [E::PENDIENTE_ADMIN],
]);

test('whoever cannot view the request still gets 403', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el material requerido');
    $ajeno = User::factory()->rol('docente')->create();
    $ajeno->licenciaturas()->attach(Licenciatura::factory()->create());

    $this->actingAs($ajeno)->get(route('reservas.show', $reserva))->assertForbidden();
});

test('viewing the detail writes nothing and keeps the stored observation and history', function () {
    $reserva = ($this->nueva)(E::PENDIENTE_COORDINADOR);
    $this->workflow->ejecutar($reserva, $this->coordinador, W::SOLICITAR_CORRECCION, 'Falta el material requerido');
    $foto = fn (): array => [
        'reserva' => Reserva::query()->findOrFail($reserva->id)->only(['estado_id', 'observaciones_admin']),
        'historial' => H::query()->orderBy('id')->get(['id', 'accion', 'comentario', 'usuario_id'])->toArray(),
        'notificaciones' => Notificacion::query()->orderBy('id')->get(['id', 'leida'])->toArray(),
    ];
    $antes = $foto();

    $escrituras = [];
    DB::listen(function ($consulta) use (&$escrituras) {
        if (preg_match('/^\s*(insert|update|delete)\b.*\b(reservas|reserva_detalles|historial_reservas|notificaciones)\b/i', $consulta->sql)) {
            $escrituras[] = $consulta->sql;
        }
    });

    foreach ([$this->docente, $this->coordinador, $this->admin] as $usuario) {
        ($this->vigente)($reserva, $usuario);
    }

    expect($escrituras)->toBe([])
        ->and($foto())->toBe($antes);
});
